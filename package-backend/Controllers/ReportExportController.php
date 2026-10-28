<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Data performa bisnis bulanan (Januari s/d Desember).
     */
    protected array $monthlyData = [
        ['month' => 'Januari',   'month_code' => 'Jan', 'orders' => 34, 'earnings_k' => 89.25,  'refunds' => 8,  'conversion_pct' => 17.5],
        ['month' => 'Februari',  'month_code' => 'Feb', 'orders' => 65, 'earnings_k' => 98.58,  'refunds' => 12, 'conversion_pct' => 18.2],
        ['month' => 'Maret',     'month_code' => 'Mar', 'orders' => 46, 'earnings_k' => 68.74,  'refunds' => 7,  'conversion_pct' => 16.8],
        ['month' => 'April',     'month_code' => 'Apr', 'orders' => 68, 'earnings_k' => 108.87, 'refunds' => 17, 'conversion_pct' => 19.4],
        ['month' => 'Mei',       'month_code' => 'May', 'orders' => 49, 'earnings_k' => 77.54,  'refunds' => 21, 'conversion_pct' => 15.9],
        ['month' => 'Juni',      'month_code' => 'Jun', 'orders' => 61, 'earnings_k' => 84.03,  'refunds' => 11, 'conversion_pct' => 18.0],
        ['month' => 'Juli',      'month_code' => 'Jul', 'orders' => 42, 'earnings_k' => 51.24,  'refunds' => 5,  'conversion_pct' => 16.2],
        ['month' => 'Agustus',   'month_code' => 'Aug', 'orders' => 44, 'earnings_k' => 28.57,  'refunds' => 9,  'conversion_pct' => 14.1],
        ['month' => 'September', 'month_code' => 'Sep', 'orders' => 78, 'earnings_k' => 92.57,  'refunds' => 7,  'conversion_pct' => 20.3],
        ['month' => 'Oktober',   'month_code' => 'Oct', 'orders' => 52, 'earnings_k' => 42.36,  'refunds' => 29, 'conversion_pct' => 15.0],
        ['month' => 'November',  'month_code' => 'Nov', 'orders' => 63, 'earnings_k' => 88.51,  'refunds' => 12, 'conversion_pct' => 18.7],
        ['month' => 'Desember',  'month_code' => 'Dec', 'orders' => 67, 'earnings_k' => 36.57,  'refunds' => 35, 'conversion_pct' => 14.8],
    ];

    /**
     * Menghasilkan file Excel (.csv / .xlsx compatible) untuk laporan eksekutif.
     * Dapat dibuka langsung oleh Microsoft Excel, Apple Numbers, atau Google Sheets.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $year = $request->query('year', date('Y'));
        $filename = "Laporan_Eksekutif_Performa_Toko_{$year}.csv";

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($year) {
            $output = fopen('php://output', 'w');

            // Tambahkan UTF-8 BOM agar Microsoft Excel di Windows membuka karakter dengan benar
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header Dokumen Eksekutif
            fputcsv($output, ["LAPORAN EKSEKUTIF PERFORMA TOKO & REVENUE BISNIS ({$year})"]);
            fputcsv($output, ["Digenerate Otomatis oleh Executive AI Assistant pada: " . date('Y-m-d H:i:s') . " WIB"]);
            fputcsv($output, []); // Baris kosong

            // Header Tabel
            fputcsv($output, [
                'No',
                'Bulan',
                'Kode Bulan',
                'Tahun',
                'Total Orders',
                'Gross Earnings ($k)',
                'Gross Earnings ($ Full)',
                'Jumlah Refund',
                'Rasio Konversi (%)',
                'Net Status'
            ]);

            $totalOrders = 0;
            $totalEarnings = 0;
            $totalRefunds = 0;

            foreach ($this->monthlyData as $idx => $row) {
                $earningsFull = $row['earnings_k'] * 1000;
                $totalOrders += $row['orders'];
                $totalEarnings += $row['earnings_k'];
                $totalRefunds += $row['refunds'];

                fputcsv($output, [
                    $idx + 1,
                    $row['month'],
                    $row['month_code'],
                    $year,
                    $row['orders'],
                    '$' . number_format($row['earnings_k'], 2) . 'k',
                    '$' . number_format($earningsFull, 2),
                    $row['refunds'],
                    $row['conversion_pct'] . '%',
                    $row['refunds'] > 20 ? 'Perlu Evaluasi' : 'Normal / Bagus'
                ]);
            }

            // Baris Total / Summary
            fputcsv($output, []);
            fputcsv($output, [
                'TOTAL',
                '12 Bulan',
                '-',
                $year,
                $totalOrders,
                '$' . number_format($totalEarnings, 2) . 'k',
                '$' . number_format($totalEarnings * 1000, 2),
                $totalRefunds,
                '17.1% (Rata-rata)',
                'Audit Selesai'
            ]);

            fclose($output);
        }, 200, $headers);
    }

    /**
     * Menghasilkan URL Chart / Grafik Visual Beresolusi Tinggi (QuickChart / Chart.js).
     */
    public function getChartUrl(Request $request)
    {
        $type = $request->query('type', 'bar'); // bar, line, radar
        $year = $request->query('year', date('Y'));

        $labels   = array_column($this->monthlyData, 'month_code');
        $earnings = array_column($this->monthlyData, 'earnings_k');
        $orders   = array_column($this->monthlyData, 'orders');

        $chartConfig = [
            'type' => $type,
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label'           => 'Earnings ($k) - ' . $year,
                        'data'            => $earnings,
                        'backgroundColor' => 'rgba(18, 140, 126, 0.85)', // Warna khas WhatsApp Green
                        'borderColor'     => '#075e54',
                        'borderWidth'     => 1,
                    ],
                    [
                        'type'            => 'line',
                        'label'           => 'Jumlah Orders',
                        'data'            => $orders,
                        'borderColor'     => '#f59e0b', // Amber/Orange
                        'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                        'borderWidth'     => 2,
                        'fill'            => false,
                        'yAxisID'         => 'y1',
                    ]
                ]
            ],
            'options' => [
                'title' => [
                    'display' => true,
                    'text'    => "Tren Performa Penjualan & Order Eksekutif ({$year})",
                    'fontColor' => '#111827',
                    'fontSize'  => 16
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'id'       => 'y0',
                            'type'     => 'linear',
                            'position' => 'left',
                            'scaleLabel' => [
                                'display'     => true,
                                'labelString' => 'Revenue ($k)'
                            ]
                        ],
                        [
                            'id'       => 'y1',
                            'type'     => 'linear',
                            'position' => 'right',
                            'scaleLabel' => [
                                'display'     => true,
                                'labelString' => 'Orders Count'
                            ],
                            'gridLines' => [
                                'drawOnChartArea' => false
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $encodedConfig = urlencode(json_encode($chartConfig));
        $quickChartUrl = "https://quickchart.io/chart?w=600&h=380&devicePixelRatio=2&c={$encodedConfig}";

        if ($request->query('redirect') === '1') {
            return redirect($quickChartUrl);
        }

        return response()->json([
            'status'     => 'success',
            'chart_type' => $type,
            'year'       => $year,
            'chart_url'  => $quickChartUrl,
            'download_excel_url' => url('/api/v1/reports/store-metrics/excel'),
        ]);
    }

    /**
     * Menyajikan file biner gambar PNG asli dari grafik untuk media WhatsApp (Direct .png).
     */
    public function getChartImage(Request $request)
    {
        $year = $request->query('year', date('Y'));
        $chartConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
                'datasets' => [
                    [
                        'label'           => 'Earnings ($k) - ' . $year,
                        'data'            => [89.25, 98.58, 68.74, 108.87, 77.54, 84.03, 51.24, 28.57, 92.57, 42.36, 88.51, 36.57],
                        'backgroundColor' => 'rgba(18, 140, 126, 0.85)',
                        'borderColor'     => '#075e54',
                        'borderWidth'     => 1,
                    ],
                    [
                        'type'            => 'line',
                        'label'           => 'Jumlah Orders',
                        'data'            => [34, 65, 46, 68, 49, 61, 42, 44, 78, 52, 63, 67],
                        'borderColor'     => '#f59e0b',
                        'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                        'borderWidth'     => 2,
                        'fill'            => false,
                        'yAxisID'         => 'y1',
                    ]
                ]
            ],
            'options' => [
                'title' => [
                    'display'   => true,
                    'text'      => "Tren Performa Penjualan & Order Eksekutif ({$year})",
                    'fontColor' => '#111827',
                    'fontSize'  => 16
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'id'         => 'y0',
                            'type'       => 'linear',
                            'position'   => 'left',
                            'scaleLabel' => ['display' => true, 'labelString' => 'Revenue ($k)']
                        ],
                        [
                            'id'         => 'y1',
                            'type'       => 'linear',
                            'position'   => 'right',
                            'scaleLabel' => ['display' => true, 'labelString' => 'Orders Count'],
                            'gridLines'  => ['drawOnChartArea' => false]
                        ]
                    ]
                ]
            ]
        ];

        $quickChartUrl = "https://quickchart.io/chart?w=600&h=380&devicePixelRatio=2&c=" . urlencode(json_encode($chartConfig));

        try {
            $imageBinary = \Illuminate\Support\Facades\Http::timeout(10)->get($quickChartUrl)->body();
            return response($imageBinary, 200, [
                'Content-Type'        => 'image/png',
                'Content-Disposition' => 'inline; filename="chart_' . $year . '.png"',
                'Cache-Control'       => 'public, max-age=3600',
            ]);
        } catch (\Throwable $e) {
            return redirect($quickChartUrl);
        }
    }
}
