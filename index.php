<?php
require_once __DIR__ . '/auth.php';

$isAuthenticated = !empty($_SESSION['user_id']);
if (!$isAuthenticated) {
    header('Location: login.php');
    exit;
}

function validDate($value, $format)
{
    $date = DateTime::createFromFormat('!' . $format, $value);
    return $date && $date->format($format) === $value;
}

function normalizeWhatsAppNumber($value)
{
    $number = preg_replace('/[\s().-]+/', '', trim((string) $value));
    if ($number === '') {
        return '';
    }
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $number)) {
        throw new RuntimeException('Enter the WhatsApp number in international format, e.g. +923001234567.');
    }
    return $number;
}

$currentMonth = date('Y-m');
$selectedMonth = isset($_GET['month']) && validDate($_GET['month'], 'Y-m') ? $_GET['month'] : $currentMonth;
$selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$setupError = $authSetupError;
$ready = false;
if (isset($_GET['export']) && $_GET['export'] === 'fee_csv' && $isAuthenticated) {
    // CSV export is prepared after fee settings and records are loaded below.
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS classes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS students (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            class_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            roll_number VARCHAR(50) NOT NULL UNIQUE,
            parent_phone VARCHAR(20) NULL,
            monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $phoneColumn = $conn->query("SHOW COLUMNS FROM students LIKE 'parent_phone'");
    if ($phoneColumn->num_rows === 0) {
        $conn->query('ALTER TABLE students ADD COLUMN parent_phone VARCHAR(20) NULL AFTER roll_number');
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fee_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            fee_month CHAR(7) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            paid_on DATE NOT NULL,
            UNIQUE KEY unique_student_fee_month (student_id, fee_month),
            CONSTRAINT fk_fee_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fee_payment_transactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            fee_month CHAR(7) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            paid_on DATE NOT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_fee_transaction_payment FOREIGN KEY (payment_id) REFERENCES fee_payments(id) ON DELETE CASCADE,
            CONSTRAINT fk_fee_transaction_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fee_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
            late_fee_per_day DECIMAL(10,2) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "INSERT IGNORE INTO fee_settings (id, due_day, late_fee_per_day) VALUES (1, 10, 0)"
    );
    $ready = $authSetupError === '';
} catch (mysqli_sql_exception $exception) {
    $setupError = 'Database setup failed. Check that the database exists and the account has permission to create tables.';
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthenticated) {
    try {
        if (
            empty($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
        ) {
            throw new RuntimeException('Your form session expired. Please try again.');
        }

        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $message = '';

        if ($action === 'add_class') {
            $className = trim(isset($_POST['class_name']) ? $_POST['class_name'] : '');
            if ($className === '' || strlen($className) > 100) {
                throw new RuntimeException('Enter a class name of up to 100 characters.');
            }
            $statement = $conn->prepare('INSERT INTO classes (name) VALUES (?)');
            $statement->bind_param('s', $className);
            $statement->execute();
            $selectedClassId = (int) $conn->insert_id;
            $message = 'Class added successfully.';
        } elseif ($action === 'add_student') {
            $studentName = trim(isset($_POST['student_name']) ? $_POST['student_name'] : '');
            $rollNumber = trim(isset($_POST['roll_number']) ? $_POST['roll_number'] : '');
            $parentPhone = normalizeWhatsAppNumber(isset($_POST['parent_phone']) ? $_POST['parent_phone'] : '');
            $studentClassId = isset($_POST['student_class_id']) ? (int) $_POST['student_class_id'] : 0;
            $monthlyFeeValue = isset($_POST['monthly_fee']) ? $_POST['monthly_fee'] : '';
            $monthlyFee = is_numeric($monthlyFeeValue) ? (float) $monthlyFeeValue : -1;

            if ($studentName === '' || strlen($studentName) > 150 || $rollNumber === '' || strlen($rollNumber) > 50) {
                throw new RuntimeException('Enter the student name and roll number.');
            }
            if ($studentClassId < 1 || $monthlyFee < 0) {
                throw new RuntimeException('Choose a class and enter a valid monthly fee.');
            }

            $statement = $conn->prepare('INSERT INTO students (class_id, name, roll_number, parent_phone, monthly_fee) VALUES (?, ?, ?, ?, ?)');
            $statement->bind_param('isssd', $studentClassId, $studentName, $rollNumber, $parentPhone, $monthlyFee);
            $statement->execute();
            $selectedClassId = $studentClassId;
            $message = 'Student added successfully.';
        } elseif ($action === 'record_fee_payment') {
            $studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
            $feeMonth = isset($_POST['fee_month']) ? $_POST['fee_month'] : '';
            $paymentAmountValue = isset($_POST['payment_amount']) ? $_POST['payment_amount'] : '';
            $paidOn = isset($_POST['paid_on']) ? $_POST['paid_on'] : date('Y-m-d');
            $note = trim(isset($_POST['note']) ? $_POST['note'] : '');
            $paymentAmount = is_numeric($paymentAmountValue) ? (float) $paymentAmountValue : -1;
            if ($studentId < 1 || !validDate($feeMonth, 'Y-m')) {
                throw new RuntimeException('Choose a valid student and fee month.');
            }
            if ($paymentAmount <= 0 || !validDate($paidOn, 'Y-m-d')) {
                throw new RuntimeException('Enter a valid payment amount and date.');
            }

            $studentStatement = $conn->prepare('SELECT monthly_fee FROM students WHERE id = ?');
            $studentStatement->bind_param('i', $studentId);
            $studentStatement->execute();
            $studentResult = $studentStatement->get_result();
            $studentRecord = $studentResult->fetch_assoc();
            if (!$studentRecord) {
                throw new RuntimeException('Student was not found.');
            }

            $existingStatement = $conn->prepare('SELECT id, amount FROM fee_payments WHERE student_id = ? AND fee_month = ?');
            $existingStatement->bind_param('is', $studentId, $feeMonth);
            $existingStatement->execute();
            $existing = $existingStatement->get_result()->fetch_assoc();
            $alreadyPaid = $existing ? (float) $existing['amount'] : 0;
            $monthlyFee = (float) $studentRecord['monthly_fee'];
            if ($alreadyPaid + $paymentAmount > $monthlyFee + 0.01) {
                throw new RuntimeException('Payment exceeds the remaining fee balance.');
            }
            if ($existing) {
                $paymentId = (int) $existing['id'];
                $newAmount = $alreadyPaid + $paymentAmount;
                $ledgerStatement = $conn->prepare('UPDATE fee_payments SET amount = ?, paid_on = ? WHERE id = ?');
                $ledgerStatement->bind_param('dsi', $newAmount, $paidOn, $paymentId);
            } else {
                $ledgerStatement = $conn->prepare(
                    'INSERT INTO fee_payments (student_id, fee_month, amount, paid_on) VALUES (?, ?, ?, ?)'
                );
                $ledgerStatement->bind_param('isds', $studentId, $feeMonth, $paymentAmount, $paidOn);
                $ledgerStatement->execute();
                $paymentId = (int) $conn->insert_id;
            }
            if ($existing) {
                $ledgerStatement->execute();
            }
            $transactionStatement = $conn->prepare(
                'INSERT INTO fee_payment_transactions (payment_id, student_id, fee_month, amount, paid_on, note) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $transactionStatement->bind_param('iisdss', $paymentId, $studentId, $feeMonth, $paymentAmount, $paidOn, $note);
            $transactionStatement->execute();
            $message = 'Fee payment recorded.';
        } elseif ($action === 'undo_fee_payment') {
            $transactionId = isset($_POST['transaction_id']) ? (int) $_POST['transaction_id'] : 0;
            if ($transactionId < 1) {
                throw new RuntimeException('Payment transaction not found.');
            }
            $transactionStatement = $conn->prepare(
                'SELECT id, payment_id, amount FROM fee_payment_transactions WHERE id = ?'
            );
            $transactionStatement->bind_param('i', $transactionId);
            $transactionStatement->execute();
            $transaction = $transactionStatement->get_result()->fetch_assoc();
            if (!$transaction) {
                throw new RuntimeException('Payment transaction not found.');
            }
            $paymentId = (int) $transaction['payment_id'];
            $deleteStatement = $conn->prepare('DELETE FROM fee_payment_transactions WHERE id = ?');
            $deleteStatement->bind_param('i', $transactionId);
            $deleteStatement->execute();

            $sumStatement = $conn->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total FROM fee_payment_transactions WHERE payment_id = ?'
            );
            $sumStatement->bind_param('i', $paymentId);
            $sumStatement->execute();
            $remaining = (float) $sumStatement->get_result()->fetch_assoc()['total'];
            if ($remaining <= 0) {
                $deletePayment = $conn->prepare('DELETE FROM fee_payments WHERE id = ?');
                $deletePayment->bind_param('i', $paymentId);
                $deletePayment->execute();
            } else {
                $updatePayment = $conn->prepare(
                    'UPDATE fee_payments SET amount = ?, paid_on = (SELECT MAX(paid_on) FROM fee_payment_transactions WHERE payment_id = ?) WHERE id = ?'
                );
                $updatePayment->bind_param('dii', $remaining, $paymentId, $paymentId);
                $updatePayment->execute();
            }
            $message = 'Payment undone.';
        } elseif ($action === 'save_fee_settings') {
            $dueDay = isset($_POST['due_day']) ? (int) $_POST['due_day'] : 0;
            $lateFeeValue = isset($_POST['late_fee_per_day']) ? $_POST['late_fee_per_day'] : '';
            $lateFee = is_numeric($lateFeeValue) ? (float) $lateFeeValue : -1;
            if ($dueDay < 1 || $dueDay > 28 || $lateFee < 0) {
                throw new RuntimeException('Enter a valid due day (1-28) and late fee.');
            }
            $statement = $conn->prepare(
                'UPDATE fee_settings SET due_day = ?, late_fee_per_day = ? WHERE id = 1'
            );
            $statement->bind_param('id', $dueDay, $lateFee);
            $statement->execute();
            $message = 'Fee settings saved.';
        } elseif ($action === 'save_parent_phone') {
            $studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
            $parentPhone = normalizeWhatsAppNumber(isset($_POST['parent_phone']) ? $_POST['parent_phone'] : '');
            if ($studentId < 1 || $parentPhone === '') {
                throw new RuntimeException('Enter a student and parent WhatsApp number.');
            }

            $studentStatement = $conn->prepare('SELECT id FROM students WHERE id = ?');
            $studentStatement->bind_param('i', $studentId);
            $studentStatement->execute();
            if (!$studentStatement->get_result()->fetch_assoc()) {
                throw new RuntimeException('Student was not found.');
            }

            $statement = $conn->prepare('UPDATE students SET parent_phone = ? WHERE id = ?');
            $statement->bind_param('si', $parentPhone, $studentId);
            $statement->execute();
            $message = 'Parent WhatsApp number saved.';
        } else {
            throw new RuntimeException('Unknown request.');
        }

        $_SESSION['flash'] = array('type' => 'success', 'message' => $message);
    } catch (mysqli_sql_exception $exception) {
        $message = $exception->getCode() === 1062
            ? 'That class name or roll number already exists.'
            : 'Could not save the information. Check the entered details and try again.';
        $_SESSION['flash'] = array('type' => 'error', 'message' => $message);
    } catch (RuntimeException $exception) {
        $_SESSION['flash'] = array('type' => 'error', 'message' => $exception->getMessage());
    }

    $query = http_build_query(array(
        'class_id' => $selectedClassId,
        'month' => $selectedMonth
    ));
    header('Location: index.php?' . $query);
    exit;
}

$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
unset($_SESSION['flash']);
$classes = array();
$unpaidStudents = array();
$classFeeReports = array();
$selectedClassName = 'All classes';
$paidTotal = 0;
$unpaidTotal = 0;
$feeSettings = array('due_day' => 10, 'late_fee_per_day' => 0);
$paymentHistory = array();
$yearlyReport = array();

if ($ready && $isAuthenticated) {
    $classResult = $conn->query('SELECT id, name FROM classes ORDER BY name');
    while ($classRow = $classResult->fetch_assoc()) {
        $classes[] = $classRow;
    }

    $classExists = false;
    foreach ($classes as $classRow) {
        if ((int) $classRow['id'] === $selectedClassId) {
            $classExists = true;
            $selectedClassName = $classRow['name'];
            break;
        }
    }
    if (!$classExists) {
        $selectedClassId = count($classes) ? (int) $classes[0]['id'] : 0;
        $selectedClassName = count($classes) ? $classes[0]['name'] : 'All classes';
    }

    $feeSettings = $conn->query('SELECT due_day, late_fee_per_day FROM fee_settings WHERE id = 1')->fetch_assoc();
    $feeSettings = $feeSettings ? $feeSettings : array('due_day' => 10, 'late_fee_per_day' => 0);

    $unpaidSql = "SELECT s.id, s.name, s.roll_number, s.parent_phone, s.monthly_fee,
                         COALESCE(f.amount, 0) AS paid_amount
                  FROM students s
                  LEFT JOIN fee_payments f ON f.student_id = s.id AND f.fee_month = ?
                  WHERE COALESCE(f.amount, 0) < s.monthly_fee";
    if ($selectedClassId > 0) {
        $unpaidSql .= ' AND s.class_id = ?';
    }
    $unpaidSql .= ' ORDER BY s.name';
    $unpaidStatement = $conn->prepare($unpaidSql);
    if ($selectedClassId > 0) {
        $unpaidStatement->bind_param('si', $selectedMonth, $selectedClassId);
    } else {
        $unpaidStatement->bind_param('s', $selectedMonth);
    }
    $unpaidStatement->execute();
    $unpaidResult = $unpaidStatement->get_result();
    while ($unpaidRow = $unpaidResult->fetch_assoc()) {
        $unpaidStudents[] = $unpaidRow;
        $balance = max(0, (float) $unpaidRow['monthly_fee'] - (float) $unpaidRow['paid_amount']);
        $dueDate = $selectedMonth . '-' . str_pad((string) (int) $feeSettings['due_day'], 2, '0', STR_PAD_LEFT);
        $lateFee = 0;
        if ($balance > 0 && date('Y-m-d') > $dueDate) {
            $dueTimestamp = strtotime($dueDate);
            $lateDays = max(0, (int) floor((time() - $dueTimestamp) / 86400));
            $lateFee = $lateDays * (float) $feeSettings['late_fee_per_day'];
        }
        $unpaidRow['balance'] = $balance;
        $unpaidRow['late_fee'] = $lateFee;
        $unpaidStudents[count($unpaidStudents) - 1] = $unpaidRow;
        $unpaidTotal += $balance + $lateFee;
    }

    $paidSql = 'SELECT COALESCE(SUM(f.amount), 0) AS total FROM fee_payments f';
    if ($selectedClassId > 0) {
        $paidSql .= ' INNER JOIN students s ON s.id = f.student_id WHERE f.fee_month = ? AND s.class_id = ?';
        $paidStatement = $conn->prepare($paidSql);
        $paidStatement->bind_param('si', $selectedMonth, $selectedClassId);
    } else {
        $paidSql .= ' WHERE f.fee_month = ?';
        $paidStatement = $conn->prepare($paidSql);
        $paidStatement->bind_param('s', $selectedMonth);
    }
    $paidStatement->execute();
    $paidTotal = (float) $paidStatement->get_result()->fetch_assoc()['total'];

    foreach ($classes as $classRow) {
        $classId = (int) $classRow['id'];
        $classFeeReports[$classId] = array(
            'name' => $classRow['name'],
            'total' => 0,
            'paid' => 0,
            'paid_students' => array(),
            'unpaid' => array()
        );
    }

    $feeReportStatement = $conn->prepare(
        "SELECT c.id AS class_id, s.id AS student_id, s.name, s.roll_number, s.monthly_fee,
                COALESCE(f.amount, 0) AS paid_amount
         FROM classes c
         LEFT JOIN students s ON s.class_id = c.id
         LEFT JOIN fee_payments f ON f.student_id = s.id AND f.fee_month = ?
         ORDER BY c.name, s.name"
    );
    $feeReportStatement->bind_param('s', $selectedMonth);
    $feeReportStatement->execute();
    $feeReportResult = $feeReportStatement->get_result();
    while ($feeReportRow = $feeReportResult->fetch_assoc()) {
        $classId = (int) $feeReportRow['class_id'];
        if ($feeReportRow['student_id'] === null) {
            continue;
        }

        $classFeeReports[$classId]['total']++;
        $paidAmount = (float) $feeReportRow['paid_amount'];
        $monthlyFee = (float) $feeReportRow['monthly_fee'];
        if (($monthlyFee <= 0) || $paidAmount >= $monthlyFee) {
            $classFeeReports[$classId]['paid']++;
            $classFeeReports[$classId]['paid_students'][] = $feeReportRow;
        } else {
            $classFeeReports[$classId]['unpaid'][] = $feeReportRow;
        }
    }

    $historySql = 'SELECT t.id, t.student_id, t.fee_month, t.amount, t.paid_on, t.note,
                          s.name AS student_name, s.roll_number
                   FROM fee_payment_transactions t
                   JOIN students s ON s.id = t.student_id';
    if ($selectedClassId > 0) {
        $historySql .= ' WHERE s.class_id = ?';
    }
    $historySql .= ' ORDER BY t.paid_on DESC, t.id DESC LIMIT 100';
    $historyStatement = $selectedClassId > 0
        ? $conn->prepare($historySql)
        : null;
    if ($selectedClassId > 0) {
        $historyStatement->bind_param('i', $selectedClassId);
        $historyStatement->execute();
        $historyResult = $historyStatement->get_result();
    } else {
        $historyResult = $conn->query($historySql);
    }
    while ($historyRow = $historyResult->fetch_assoc()) {
        $paymentHistory[] = $historyRow;
    }

    $year = substr($selectedMonth, 0, 4);
    $yearlySql = 'SELECT LEFT(fee_month, 4) AS year, fee_month,
                         COALESCE(SUM(amount), 0) AS total
                  FROM fee_payments
                  WHERE fee_month LIKE ?';
    if ($selectedClassId > 0) {
        $yearlySql .= ' AND student_id IN (SELECT id FROM students WHERE class_id = ?)';
    }
    $yearlySql .= ' GROUP BY year, fee_month ORDER BY fee_month';
    $yearlyStatement = $selectedClassId > 0
        ? $conn->prepare($yearlySql)
        : $conn->prepare($yearlySql);
    $yearPattern = $year . '-%';
    if ($selectedClassId > 0) {
        $yearlyStatement->bind_param('si', $yearPattern, $selectedClassId);
    } else {
        $yearlyStatement->bind_param('s', $yearPattern);
    }
    $yearlyStatement->execute();
    $yearlyResult = $yearlyStatement->get_result();
    while ($yearlyRow = $yearlyResult->fetch_assoc()) {
        $yearlyReport[] = $yearlyRow;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'fee_csv' && $ready && $isAuthenticated) {
    $exportSql = "SELECT c.name AS class_name, s.name, s.roll_number, s.monthly_fee,
                         COALESCE(f.amount, 0) AS paid_amount,
                         GREATEST(s.monthly_fee - COALESCE(f.amount, 0), 0) AS balance
                  FROM students s
                  JOIN classes c ON c.id = s.class_id
                  LEFT JOIN fee_payments f ON f.student_id = s.id AND f.fee_month = ?";
    if ($selectedClassId > 0) {
        $exportSql .= ' WHERE s.class_id = ?';
    }
    $exportSql .= ' ORDER BY c.name, s.name';
    $exportStatement = $selectedClassId > 0
        ? $conn->prepare($exportSql)
        : $conn->prepare($exportSql);
    if ($selectedClassId > 0) {
        $exportStatement->bind_param('si', $selectedMonth, $selectedClassId);
    } else {
        $exportStatement->bind_param('s', $selectedMonth);
    }
    $exportStatement->execute();
    $exportResult = $exportStatement->get_result();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fee-report-' . $selectedMonth . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Class', 'Student', 'Roll No.', 'Monthly Fee', 'Paid', 'Balance', 'Status'));
    while ($row = $exportResult->fetch_assoc()) {
        $balance = (float) $row['balance'];
        fputcsv($output, array(
            $row['class_name'],
            $row['name'],
            $row['roll_number'],
            number_format((float) $row['monthly_fee'], 2, '.', ''),
            number_format((float) $row['paid_amount'], 2, '.', ''),
            number_format($balance, 2, '.', ''),
            $balance <= 0.01 ? 'Paid' : 'Unpaid'
        ));
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>School Dashboard</title>
    <style>
        :root {
            --ink: #17233c;
            --muted: #718096;
            --line: #e7ecf3;
            --blue: #4f46e5;
            --blue-dark: #3730a3;
            --bg: #f4f7fb;
            --card-shadow: 0 8px 24px rgba(31, 45, 61, .07);
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: var(--bg); font: 15px/1.55 "Segoe UI", Arial, sans-serif; }
        header {
            padding: 22px max(22px, calc((100% - 1180px) / 2));
            color: white;
            background: linear-gradient(120deg, #182b56 0%, #243b73 60%, #3156d3 100%);
            box-shadow: 0 4px 18px rgba(24, 43, 86, .16);
        }
        header h1 { margin: 0; font-size: 25px; letter-spacing: -.3px; }
        header p { margin: 4px 0 0; color: #dbe5f5; }
        main { max-width: 1180px; margin: 24px auto; padding: 0 20px 44px; }
        .toolbar, .panel, .stat {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: white;
            box-shadow: var(--card-shadow);
        }
        .toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 16px; margin-bottom: 18px; }
        .toolbar label { min-width: 170px; flex: 1; }
        label { display: block; color: #4a5568; font-size: 13px; font-weight: 700; }
        input, select {
            width: 100%;
            margin-top: 6px;
            padding: 10px 12px;
            border: 1px solid #d8e0ec;
            border-radius: 9px;
            color: var(--ink);
            background: #fff;
            font: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, .12); }
        button {
            min-height: 42px;
            padding: 10px 15px;
            border: 0;
            border-radius: 9px;
            color: white;
            background: var(--blue);
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            transition: background .15s, transform .15s, box-shadow .15s;
        }
        button:hover { background: var(--blue-dark); transform: translateY(-1px); box-shadow: 0 5px 14px rgba(79, 70, 229, .22); }
        button.secondary { background: #334155; }
        button.secondary:hover { background: #1e293b; }
        button.small { min-height: 36px; padding: 7px 10px; font-size: 13px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
        .stat { position: relative; min-height: 112px; overflow: hidden; padding: 18px 19px; }
        .stat::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #94a3b8; }
        .stat.red::before { background: #ef4444; }
        .stat.green::before { background: #10b981; }
        .stat.gold::before { background: #f59e0b; }
        .stat span { display: block; color: var(--muted); font-size: 13px; font-weight: 600; }
        .stat strong { display: block; margin-top: 6px; font-size: 26px; line-height: 1.2; }
        .stat.red strong { color: #dc2626; }
        .stat.green strong { color: #059669; }
        .stat.gold strong { color: #d97706; }
        .red { color: #dc2626; }
        .grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(280px, .75fr); gap: 18px; align-items: start; }
        .panel { padding: 20px; margin-bottom: 18px; }
        .panel h2 { display: flex; align-items: center; margin: 0 0 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); font-size: 18px; letter-spacing: -.2px; }
        .panel h3 { margin: 20px 0 10px; font-size: 16px; }
        .subtext, .muted { color: var(--muted); font-size: 13px; }
        .subtext { margin-top: 0; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
        th { color: #64748b; background: #f8fafc; font-size: 11px; letter-spacing: .35px; text-transform: uppercase; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover td { background: #fbfdff; }
        td select { min-width: 120px; margin: 0; padding: 7px; }
        td input[type="tel"] { min-width: 155px; margin: 0 0 6px; padding: 7px; }
        .inline-form { display: inline; }
        .whatsapp-link {
            display: inline-block;
            padding: 8px 11px;
            border-radius: 8px;
            color: white;
            background: #16835d;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }
        .whatsapp-link:hover { background: #106b4b; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-grid .wide { grid-column: 1 / -1; }
        .flash { padding: 13px 15px; border: 1px solid transparent; border-radius: 10px; margin-bottom: 16px; }
        .flash.success { color: #166534; border-color: #bbf7d0; background: #dcfce7; }
        .flash.error, .setup-error { color: #991b1b; border-color: #fecaca; background: #fee2e2; }
        .header-row { position: relative; display: flex; justify-content: center; align-items: center; min-height: 52px; gap: 16px; text-align: center; }
        .header-row > div { width: 100%; }
        .header-row > form { position: absolute; right: 0; }
        .header-row h2 { flex: 1; }
        .header-row form { margin: 0; }
        .empty { padding: 22px; border: 1px dashed #cbd5e1; border-radius: 10px; text-align: center; color: var(--muted); background: #f8fafc; }
        .pill { display: inline-block; margin: 2px; padding: 4px 8px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 12px; }
        .compact-form { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; min-width: 210px; }
        .compact-form .wide { grid-column: 1 / -1; }
        .danger { background: #dc2626; }
        .danger:hover { background: #b91c1c; }
        @media (max-width: 760px) {
            header { padding: 18px 16px; }
            main { margin-top: 16px; padding: 0 12px 30px; }
            .toolbar label { flex: 1 1 140px; min-width: 140px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid { grid-template-columns: 1fr; }
            .panel { padding: 15px; }
        }
        @media (max-width: 460px) {
            .stats, .form-grid { grid-template-columns: 1fr; }
            .form-grid .wide { grid-column: auto; }
            .header-row { align-items: flex-start; }
            .header-row > form { position: static; }
            .header-row h2 { font-size: 16px; }
            .stat { min-height: 100px; padding: 15px; }
            .stat strong { font-size: 21px; }
            .toolbar { align-items: stretch; }
            .toolbar label, .toolbar button { flex-basis: 100%; }
            .compact-form { grid-template-columns: 1fr; min-width: 170px; }
            .compact-form .wide { grid-column: auto; }
        }
    </style>
</head>
<body>
<header>
    <div class="header-row">
        <div>
            <h1>The Best Vision School</h1>
            <p>Class strength and monthly fee records</p>
        </div>
        <?php if ($isAuthenticated): ?>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <button class="secondary" type="submit">Logout</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if ($setupError !== ''): ?>
        <div class="flash setup-error"><?php echo h($setupError); ?> Check the database name/configuration in <strong>dbcon.php</strong>.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="flash <?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($ready): ?>
        <form class="toolbar" method="get">
            <label>Class
                <select name="class_id" onchange="this.form.submit()">
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>><?php echo h($class['name']); ?></option>
                    <?php endforeach; ?>
                    <?php if (!$classes): ?><option value="0">No classes yet</option><?php endif; ?>
                </select>
            </label>
            <label>Fee month
                <input type="month" name="month" value="<?php echo h($selectedMonth); ?>">
            </label>
            <button type="submit">Show report</button>
            <span class="subtext"><?php echo h($selectedClassName); ?></span>
        </form>

        <section class="stats">
            <div class="stat"><span>Students in <?php echo h($selectedClassName); ?></span><strong><?php echo isset($classFeeReports[$selectedClassId]) ? (int) $classFeeReports[$selectedClassId]['total'] : 0; ?></strong></div>
            <div class="stat gold"><span>Fee not received · <?php echo h($selectedMonth); ?></span><strong><?php echo count($unpaidStudents); ?></strong></div>
            <div class="stat green"><span>Fee received this month</span><strong>Rs <?php echo number_format($paidTotal, 2); ?></strong></div>
            <div class="stat red"><span>Fee outstanding</span><strong>Rs <?php echo number_format($unpaidTotal, 2); ?></strong></div>
        </section>

        <section class="panel">
            <h2>Fee settings</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save_fee_settings">
                <label>Due day of month
                    <input type="number" name="due_day" min="1" max="28" value="<?php echo (int) $feeSettings['due_day']; ?>" required>
                </label>
                <label>Late fee per day (Rs)
                    <input type="number" name="late_fee_per_day" min="0" step="0.01" value="<?php echo h(number_format((float) $feeSettings['late_fee_per_day'], 2, '.', '')); ?>" required>
                </label>
                <div class="wide"><button type="submit">Save fee settings</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="header-row">
                <h2>Payment history</h2>
                <a class="whatsapp-link" href="index.php?class_id=<?php echo $selectedClassId; ?>&amp;month=<?php echo h($selectedMonth); ?>&amp;export=fee_csv">Export CSV</a>
            </div>
            <?php if ($paymentHistory): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>Student</th><th>Month</th><th>Amount</th><th>Note</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?php echo h($payment['paid_on']); ?></td>
                                <td><?php echo h($payment['student_name']); ?> (<?php echo h($payment['roll_number']); ?>)</td>
                                <td><?php echo h($payment['fee_month']); ?></td>
                                <td>Rs <?php echo number_format((float) $payment['amount'], 2); ?></td>
                                <td><?php echo h($payment['note'] !== '' ? $payment['note'] : '-'); ?></td>
                                <td>
                                    <a class="whatsapp-link" href="receipt.php?id=<?php echo (int) $payment['id']; ?>" target="_blank">Receipt</a>
                                    <form class="inline-form" method="post" onsubmit="return confirm('Undo this payment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="undo_fee_payment">
                                        <input type="hidden" name="transaction_id" value="<?php echo (int) $payment['id']; ?>">
                                        <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                                        <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                                        <button class="small danger" type="submit">Undo</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">No payments have been recorded yet.</div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Yearly collection · <?php echo h(substr($selectedMonth, 0, 4)); ?></h2>
            <?php if ($yearlyReport): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Month</th><th>Collected</th></tr></thead>
                        <tbody>
                        <?php foreach ($yearlyReport as $yearly): ?>
                            <tr>
                                <td><?php echo h($yearly['fee_month']); ?></td>
                                <td>Rs <?php echo number_format((float) $yearly['total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">No collections have been recorded for this year.</div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Students in each class</h2>
            <?php if ($classFeeReports): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Class</th><th>Total students</th></tr></thead>
                        <tbody>
                        <?php foreach ($classFeeReports as $classFeeReport): ?>
                            <tr>
                                <td><?php echo h($classFeeReport['name']); ?></td>
                                <td><?php echo (int) $classFeeReport['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">No classes have been added yet.</div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Monthly fee report by class · <?php echo h($selectedMonth); ?></h2>
            <p class="subtext">View paid and unpaid students for each class.</p>
            <?php if ($classFeeReports): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Class</th><th>Total students</th><th>Fee paid</th><th>Fee unpaid</th><th>Paid students</th><th>Unpaid students</th></tr></thead>
                        <tbody>
                        <?php foreach ($classFeeReports as $classFeeReport): ?>
                            <tr>
                                <td><?php echo h($classFeeReport['name']); ?></td>
                                <td><?php echo (int) $classFeeReport['total']; ?></td>
                                <td><?php echo (int) $classFeeReport['paid']; ?></td>
                                <td><?php echo count($classFeeReport['unpaid']); ?></td>
                                <td>
                                    <?php if ($classFeeReport['paid_students']): ?>
                                        <?php foreach ($classFeeReport['paid_students'] as $paidStudent): ?>
                                            <span class="pill"><?php echo h($paidStudent['name']); ?> (<?php echo h($paidStudent['roll_number']); ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($classFeeReport['unpaid']): ?>
                                        <?php foreach ($classFeeReport['unpaid'] as $unpaidStudent): ?>
                                            <span class="pill"><?php echo h($unpaidStudent['name']); ?> (<?php echo h($unpaidStudent['roll_number']); ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">Add a class first to see the monthly fee report.</div>
            <?php endif; ?>
        </section>

        <div class="grid">
            <div>
                <section class="panel">
                    <h2>Fee not received · <?php echo h($selectedMonth); ?></h2>
                    <p class="subtext">Click WhatsApp Alert to open a prepared message. Review and send it manually. Enter the number with its country code.</p>
                    <?php if ($unpaidStudents): ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Roll no.</th><th>Student</th><th>Monthly fee</th><th>Parent WhatsApp</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($unpaidStudents as $student): ?>
                                    <tr>
                                        <td><?php echo h($student['roll_number']); ?></td>
                                        <td><?php echo h($student['name']); ?></td>
                                        <td>
                                            Rs <?php echo number_format((float) $student['monthly_fee'], 2); ?><br>
                                            <span class="muted">Paid: Rs <?php echo number_format((float) $student['paid_amount'], 2); ?></span><br>
                                            <span class="muted">Balance: Rs <?php echo number_format((float) $student['balance'], 2); ?></span>
                                            <?php if ((float) $student['late_fee'] > 0): ?>
                                                <br><span class="red">Late fee: Rs <?php echo number_format((float) $student['late_fee'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form class="inline-form" method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="save_parent_phone">
                                                <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                                                <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                                                <input type="tel" name="parent_phone" value="<?php echo h($student['parent_phone']); ?>" placeholder="+923001234567" aria-label="Parent WhatsApp number for <?php echo h($student['name']); ?>" required>
                                                <button class="small secondary" type="submit">Save number</button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if (!empty($student['parent_phone'])): ?>
                                                <?php
                                                $whatsAppNumber = preg_replace('/\D+/', '', $student['parent_phone']);
                                                $whatsAppMessage = 'Hello. The monthly fee for ' . $student['name'] . ' (Roll No. ' . $student['roll_number'] . ') for ' . $selectedMonth . ' is still unpaid: Rs ' . number_format((float) $student['balance'], 2) . '. If the fee has already been paid, please ignore this message. The Best Vision School.';
                                                $whatsAppUrl = 'https://wa.me/' . $whatsAppNumber . '?text=' . rawurlencode($whatsAppMessage);
                                                ?>
                                                <a class="whatsapp-link" href="<?php echo h($whatsAppUrl); ?>" target="_blank" rel="noopener noreferrer">WhatsApp alert</a>
                                            <?php else: ?>
                                                <span class="muted">Save the number first</span>
                                            <?php endif; ?>
                                            <form class="compact-form" method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="record_fee_payment">
                                                <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                                <input type="hidden" name="fee_month" value="<?php echo h($selectedMonth); ?>">
                                                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                                                <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                                                <label>Amount
                                                    <input type="number" name="payment_amount" min="0.01" step="0.01" max="<?php echo h(number_format((float) $student['monthly_fee'] - (float) $student['paid_amount'], 2, '.', '')); ?>" required>
                                                </label>
                                                <label>Date
                                                    <input type="date" name="paid_on" value="<?php echo date('Y-m-d'); ?>" required>
                                                </label>
                                                <label class="wide">Note
                                                    <input name="note" maxlength="255" placeholder="Cash, bank, etc.">
                                                </label>
                                                <button class="small wide" type="submit">Record payment</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty">All fees have been received for this month.</div>
                    <?php endif; ?>
                </section>
            </div>

            <aside>
                <section class="panel">
                    <h2>Add class</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="add_class">
                        <label>Class name<input name="class_name" maxlength="100" placeholder="e.g. Class 1" required></label>
                        <p><button type="submit">Add class</button></p>
                    </form>
                </section>
                <?php if ($classes): ?>
                    <section class="panel">
                        <h2>Add student</h2>
                        <form method="post" class="form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="add_student">
                            <label class="wide">Student name<input name="student_name" maxlength="150" required></label>
                            <label>Roll number<input name="roll_number" maxlength="50" required></label>
                            <label>Class
                                <select name="student_class_id" required>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>><?php echo h($class['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="wide">Parent/guardian WhatsApp (country code ke saath)<input type="tel" name="parent_phone" maxlength="20" placeholder="+923001234567"></label>
                            <label class="wide">Monthly fee (Rs)<input type="number" name="monthly_fee" min="0" step="0.01" value="0" required></label>
                            <div class="wide"><button type="submit">Add student</button></div>
                        </form>
                    </section>
                <?php endif; ?>
                <section class="panel">
                    <h2>Quick guide</h2>
                    <p class="subtext">1. Add a class, then add students, monthly fees, and parent WhatsApp numbers.</p>
                    <p class="subtext">2. Select a fee month and enter the payment amount and date. Partial and advance payments are supported.</p>
                    <p class="subtext">3. Use “WhatsApp Alert” in the unpaid list to manually send a prepared reminder.</p>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</main>
</body>
</html>