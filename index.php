<?php

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Dompdf\Dompdf;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

try {
    if ($method === 'GET' && $uri === '/api/employees') {
        getEmployees();
    } elseif ($method === 'GET' && $uri === '/api/reports/payroll') {
        getPayrollReport();
    } elseif ($method === 'GET' && preg_match('#^/api/payslips/([0-9]+)$#', $uri, $matches)) {
        getPayslip($matches[1]);
    } elseif ($method === 'POST' && preg_match('#^/api/payslips/([0-9]+)/generate$#', $uri, $matches)) {
        generatePayslipPdf($matches[1]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function readCsv($filename) {
    $filePath = __DIR__ . '/data/' . $filename;
    if (!file_exists($filePath)) {
        throw new Exception("File not found: $filename");
    }
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== FALSE) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== FALSE) {
            $rows[] = array_combine($headers, $data);
        }
        fclose($handle);
    }
    return $rows;
}

function getEmployees() {
    $employees = readCsv('employees.csv');
    $formatted = array_map(function($emp) {
        return [
            'employee_id'   => (int)($emp['employee_id'] ?? $emp['id']),
            'full_name'     => $emp['full_name'] ?? ($emp['first_name'] . ' ' . $emp['last_name']),
            'department_id' => (int)($emp['department_id'] ?? 1)
        ];
    }, $employees);

    echo json_encode(['success' => true, 'data' => $formatted]);
}

function getPayrollReport() {
    $payroll = readCsv('payroll.csv');
    $totalGross = 0; $totalDeductions = 0; $totalNet = 0;

    foreach ($payroll as $row) {
        $gross = (float)($row['gross_pay'] ?? 0);
        $net = (float)($row['net_pay'] ?? 0);
        $deductions = isset($row['deductions']) ? (float)$row['deductions'] : ($gross - $net);

        $totalGross += $gross;
        $totalDeductions += $deductions;
        $totalNet += $net;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total_payroll_records' => count($payroll),
            'total_gross_pay'       => round($totalGross, 2),
            'total_deductions'      => round($totalDeductions, 2),
            'total_net_pay'         => round($totalNet, 2)
        ]
    ]);
}

function getPayslip($payrollId) {
    $payroll = readCsv('payroll.csv');
    $employees = readCsv('employees.csv');

    $record = null;
    foreach ($payroll as $row) {
        if (($row['payroll_id'] ?? $row['id']) == $payrollId) {
            $record = $row;
            break;
        }
    }

    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payroll record not found']);
        return;
    }

    $employeeName = 'Unknown Employee';
    foreach ($employees as $emp) {
        if (($emp['employee_id'] ?? $emp['id']) == $record['employee_id']) {
            $employeeName = $emp['full_name'] ?? ($emp['first_name'] . ' ' . $emp['last_name']);
            break;
        }
    }

    $gross = (float)($record['gross_pay'] ?? 0);
    $net = (float)($record['net_pay'] ?? 0);
    $deductions = isset($record['deductions']) ? (float)$record['deductions'] : ($gross - $net);

    echo json_encode([
        'success' => true,
        'data' => [
            'payroll_id'    => (int)$payrollId,
            'employee_name' => $employeeName,
            'gross_pay'     => round($gross, 2),
            'deductions'    => round($deductions, 2),
            'net_pay'       => round($net, 2)
        ]
    ]);
}

function generatePayslipPdf($payrollId) {
    $payroll = readCsv('payroll.csv');
    $employees = readCsv('employees.csv');

    $record = null;
    foreach ($payroll as $row) {
        if (($row['payroll_id'] ?? $row['id']) == $payrollId) {
            $record = $row;
            break;
        }
    }

    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payroll record not found']);
        return;
    }

    $employeeName = 'Unknown Employee';
    foreach ($employees as $emp) {
        if (($emp['employee_id'] ?? $emp['id']) == $record['employee_id']) {
            $employeeName = $emp['full_name'] ?? ($emp['first_name'] . ' ' . $emp['last_name']);
            break;
        }
    }

    $gross = (float)($record['gross_pay'] ?? 0);
    $net = (float)($record['net_pay'] ?? 0);
    $deductions = isset($record['deductions']) ? (float)$record['deductions'] : ($gross - $net);

    $html = "
    <h2>Payslip</h2>
    <p><strong>Payroll ID:</strong> {$payrollId}</p>
    <p><strong>Employee:</strong> {$employeeName}</p>
    <p><strong>Gross Pay:</strong> $" . number_format($gross, 2) . "</p>
    <p><strong>Deductions:</strong> $" . number_format($deductions, 2) . "</p>
    <p><strong>Net Pay:</strong> $" . number_format($net, 2) . "</p>";

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->render();
    $pdfOutput = $dompdf->output();

    $bucketName = getenv('S3_BUCKET_NAME') ?: 'santiago-pay-bucket';
    $s3Key = "payslips/{$payrollId}.pdf";

    $s3Client = new S3Client([
        'version' => 'latest',
        'region'  => 'ap-southeast-2'
    ]);

    $s3Client->putObject([
        'Bucket'      => $bucketName,
        'Key'         => $s3Key,
        'Body'        => $pdfOutput,
        'ContentType' => 'application/pdf'
    ]);

    $s3Url = "https://{$bucketName}.s3.ap-southeast-2.amazonaws.com/{$s3Key}";

    echo json_encode([
        'success' => true,
        'message' => 'Payslip generated and uploaded.',
        'data' => [
            'payroll_id'   => (int)$payrollId,
            'payslip_file' => $s3Url
        ]
    ]);
}