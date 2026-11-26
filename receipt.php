<?php
require_once __DIR__ . '/auth.php';

$isAuthenticated = !empty($_SESSION['user_id']);
if (!$isAuthenticated) {
    header('Location: login.php');
    exit;
}

$transactionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($transactionId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$statement = $conn->prepare(
    'SELECT t.id, t.amount, t.paid_on, t.note, t.fee_month,
            s.name AS student_name, s.roll_number, s.monthly_fee,
            c.name AS class_name
     FROM fee_payment_transactions t
     JOIN students s ON s.id = t.student_id
     JOIN classes c ON c.id = s.class_id
     WHERE t.id = ?'
);
$statement->bind_param('i', $transactionId);
$statement->execute();
$receipt = $statement->get_result()->fetch_assoc();
if (!$receipt) {
    http_response_code(404);
    exit('Receipt not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fee Receipt</title>
    <style>
        :root { --ink: #17233c; --muted: #718096; --blue: #4f46e5; }
        * { box-sizing: border-box; }
        body { max-width: 760px; margin: 30px auto; padding: 20px; color: var(--ink); background: #f4f7fb; font: 16px "Segoe UI", Arial, sans-serif; }
        .receipt { overflow: hidden; border: 1px solid #dfe5ef; border-radius: 16px; background: white; padding: 30px; box-shadow: 0 14px 36px rgba(31, 45, 61, .10); }
        h1 { margin: 0 0 3px; color: #182b56; }
        .muted { color: var(--muted); }
        dl { display: grid; grid-template-columns: 180px 1fr; gap: 10px 18px; margin: 24px 0 0; }
        dt { color: #64748b; font-weight: 700; }
        dd { margin: 0; }
        .amount { margin-top: 24px; padding: 16px 18px; border-left: 5px solid #10b981; border-radius: 10px; background: #ecfdf5; color: #065f46; font-size: 22px; font-weight: 800; }
        button { margin-top: 18px; padding: 11px 16px; border: 0; border-radius: 9px; background: var(--blue); color: white; cursor: pointer; font: inherit; font-weight: 700; }
        button:hover { background: #3730a3; }
        @media (max-width: 560px) { body { margin: 0; padding: 12px; } .receipt { padding: 20px; } dl { grid-template-columns: 130px 1fr; } }
        @media print { body { max-width: none; margin: 0; padding: 0; background: white; } .receipt { border: 2px solid #17233c; box-shadow: none; } button { display: none; } }
    </style>
</head>
<body>
    <div class="receipt">
        <h1>The Best Vision School</h1>
        <p class="muted">Fee receipt</p>
        <dl>
            <dt>Receipt no.</dt><dd><?php echo str_pad((string) (int) $receipt['id'], 6, '0', STR_PAD_LEFT); ?></dd>
            <dt>Student</dt><dd><?php echo h($receipt['student_name']); ?></dd>
            <dt>Roll no.</dt><dd><?php echo h($receipt['roll_number']); ?></dd>
            <dt>Class</dt><dd><?php echo h($receipt['class_name']); ?></dd>
            <dt>Fee month</dt><dd><?php echo h($receipt['fee_month']); ?></dd>
            <dt>Payment date</dt><dd><?php echo h($receipt['paid_on']); ?></dd>
            <dt>Note</dt><dd><?php echo h($receipt['note'] !== '' ? $receipt['note'] : '-'); ?></dd>
        </dl>
        <div class="amount">Received: Rs <?php echo number_format((float) $receipt['amount'], 2); ?></div>
    </div>
    <button type="button" onclick="window.print()">Print receipt</button>
</body>
</html>
