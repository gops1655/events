<?php
declare(strict_types=1);

function excel_esc(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excel_cell(string $type, string $style, $value): string
{
    if ($type === 'Number') {
        if ($value === '' || $value === null) {
            return '<Cell ss:StyleID="' . $style . '"/>';
        }
        return '<Cell ss:StyleID="' . $style . '"><Data ss:Type="Number">' . (is_numeric($value) ? (0 + $value) : 0) . '</Data></Cell>';
    }
    return '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . excel_esc((string) $value) . '</Data></Cell>';
}

function excel_row(array $cells, int $height = 0): string
{
    $h = $height ? ' ss:Height="' . $height . '"' : '';
    return '<Row' . $h . '>' . implode('', $cells) . '</Row>';
}

function excel_empty(int $cols, string $style = 'pad'): string
{
    $cells = [];
    for ($i = 0; $i < $cols; $i++) {
        $cells[] = '<Cell ss:StyleID="' . $style . '"/>';
    }
    return excel_row($cells, 8);
}

function download_eventgrant_excel(array $payload): void
{
    $hospital = $payload['hospital'];
    $city = $payload['city'];
    $from = $payload['from'];
    $to = $payload['to'];
    $unit = $payload['unit'] ?: 'All units';
    $rows = $payload['rows'];
    $sponsors = $payload['sponsors'];
    $cats = $payload['cats'];
    $totPromised = (float) $payload['totPromised'];
    $totReceived = (float) $payload['totReceived'];
    $totSpend = (float) $payload['totSpend'];
    $totTarget = (float) $payload['totTarget'];
    $reportTitle = (string) ($payload['report_title'] ?? 'Event sponsorship report');
    $periodLabel = (string) ($payload['period_label'] ?? (dmy($from) . ' – ' . dmy($to)));
    $scope = (string) ($payload['scope'] ?? '');
    $spendLabel = (string) ($payload['spend_label'] ?? 'Approved spend');
    $lines = $payload['lines'] ?? [];
    $outstandingLines = $payload['outstanding_lines'] ?? [];
    $book = (string) ($payload['book'] ?? '');
    $toCollect = max(0, $totTarget - $totReceived);
    $net = $totReceived - $totSpend;
    $pct = $totPromised > 0 ? (int) round($totReceived / $totPromised * 100) : 0;

    $user = current_user();
    $who = (string) ($user['name'] ?? 'Unknown');
    $email = (string) ($user['email'] ?? '');
    $roleLabel = roles()[$user['role'] ?? ''] ?? (string) ($user['role'] ?? '');
    $when = date('d M Y, h:i A');
    $docId = 'EG-' . date('Ymd') . '-' . str_pad((string) ($user['id'] ?? 0), 3, '0', STR_PAD_LEFT);
    $stamp = strtoupper(substr(hash('sha256', implode('|', [$docId, $from, $to, $unit, $totPromised, $totReceived, $totSpend, $who, $scope])), 0, 12));
    $fileBits = ['EventGrant-Report'];
    if ($scope !== '') {
        $fileBits[] = preg_replace('/[^A-Za-z0-9]+/', '-', substr($scope, 0, 40)) ?: 'report';
    } else {
        $fileBits[] = $from . '-to-' . $to;
    }
    $file = implode('-', $fileBits) . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title><?= excel_esc($hospital) ?> — <?= excel_esc($reportTitle) ?></Title>
  <Author><?= excel_esc($who) ?></Author>
  <Company><?= excel_esc($hospital) ?></Company>
  <Created><?= date('c') ?></Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <ProtectStructure>False</ProtectStructure>
  <ProtectWindows>False</ProtectWindows>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#14202B"/></Style>
  <Style ss:ID="banner"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1B6E64" ss:Pattern="Solid"/></Style>
  <Style ss:ID="title"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="20" ss:Bold="1" ss:Color="#14202B"/></Style>
  <Style ss:ID="sub"><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#5C6B75"/></Style>
  <Style ss:ID="brass"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#8A6A22"/><Interior ss:Color="#F4E6C8" ss:Pattern="Solid"/></Style>
  <Style ss:ID="metaL"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#5C6B75"/></Style>
  <Style ss:ID="metaV"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#14202B"/></Style>
  <Style ss:ID="kpiL"><Alignment ss:Horizontal="Left"/><Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="#5C6B75"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/></Style>
  <Style ss:ID="kpiN"><Alignment ss:Horizontal="Left"/><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#1B6E64"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/></Style>
  <Style ss:ID="kpiWarn"><Alignment ss:Horizontal="Left"/><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#C45C4A"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/></Style>
  <Style ss:ID="kpiPct"><Alignment ss:Horizontal="Left"/><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#1B6E64"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/></Style>
  <Style ss:ID="pct"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/><NumberFormat ss:Format="0.0&quot;%&quot;"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="pctAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/><NumberFormat ss:Format="0.0&quot;%&quot;"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="sec"><Font ss:FontName="Calibri" ss:Size="13" ss:Bold="1" ss:Color="#1B6E64"/></Style>
  <Style ss:ID="th"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1B6E64" ss:Pattern="Solid"/></Style>
  <Style ss:ID="thR"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1B6E64" ss:Pattern="Solid"/></Style>
  <Style ss:ID="td"><Alignment ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Calibri" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="tdAlt"><Alignment ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Calibri" ss:Size="10"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="inr"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="inrAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/><Interior ss:Color="#F7F1E3" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="pos"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#2F7D5B"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="neg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#C45C4A"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6DFD2"/></Borders></Style>
  <Style ss:ID="tot"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#14202B" ss:Pattern="Solid"/></Style>
  <Style ss:ID="totN"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#14202B" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;₹&quot;#,##,##0"/></Style>
  <Style ss:ID="foot"><Font ss:FontName="Calibri" ss:Size="9" ss:Color="#5C6B75" ss:Italic="1"/></Style>
  <Style ss:ID="conf"><Alignment ss:Horizontal="Center"/><Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="#8A6A22"/><Interior ss:Color="#F4E6C8" ss:Pattern="Solid"/></Style>
  <Style ss:ID="pad"/>
  <Style ss:ID="dash"><Alignment ss:Horizontal="Right"/><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#9AA5AD"/></Style>
 </Styles>

 <Worksheet ss:Name="Summary">
  <Table ss:ExpandedColumnCount="9" ss:ExpandedRowCount="28" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="28"/><Column ss:Width="160"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="40"/><Column ss:Width="40"/><Column ss:Width="40"/>
   <Row ss:Height="24"><Cell ss:MergeAcross="5" ss:StyleID="banner"><Data ss:Type="String"><?= excel_esc(strtoupper($hospital) . ($city ? '  ·  ' . strtoupper($city) : '')) ?></Data></Cell></Row>
   <Row ss:Height="28"><Cell ss:MergeAcross="5" ss:StyleID="title"><Data ss:Type="String"><?= excel_esc($reportTitle) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="5" ss:StyleID="sub"><Data ss:Type="String">Official extract from EventGrant  ·  Document <?= excel_esc($docId) ?><?= $scope !== '' ? '  ·  ' . excel_esc($scope) : '' ?></Data></Cell></Row>
   <Row ss:Height="18"><Cell ss:MergeAcross="5" ss:StyleID="conf"><Data ss:Type="String">CONFIDENTIAL — for hospital finance and administration only</Data></Cell></Row>
   <?= excel_empty(6) ?>
   <?= excel_row([
       excel_cell('String', 'metaL', 'Period'),
       excel_cell('String', 'metaV', $periodLabel),
       excel_cell('String', 'metaL', 'Unit'),
       excel_cell('String', 'metaV', $unit),
       excel_cell('String', 'metaL', 'Programmes'),
       excel_cell('String', 'metaV', (string) count($rows)),
   ]) ?>
   <?= excel_row([
       excel_cell('String', 'metaL', 'Generated'),
       excel_cell('String', 'metaV', $when),
       excel_cell('String', 'metaL', 'Prepared by'),
       excel_cell('String', 'metaV', $who),
       excel_cell('String', 'metaL', 'Role'),
       excel_cell('String', 'metaV', $roleLabel),
   ]) ?>
   <?= excel_empty(6) ?>
   <?= excel_row([excel_cell('String', 'sec', 'Headline figures')], 22) ?>
   <?= excel_row([
       excel_cell('String', 'kpiL', 'PROMISED'),
       excel_cell('String', 'kpiL', 'RECEIVED'),
       excel_cell('String', 'kpiL', 'STILL TO COLLECT'),
       excel_cell('String', 'kpiL', strtoupper($spendLabel)),
       excel_cell('String', 'kpiL', 'NET (RECEIVED − SPEND)'),
       excel_cell('String', 'kpiL', 'COLLECTION'),
   ], 16) ?>
   <?= excel_row([
       excel_cell('Number', 'kpiN', $totPromised),
       excel_cell('Number', 'kpiN', $totReceived),
       excel_cell('Number', 'kpiN', $toCollect),
       excel_cell('Number', 'kpiN', $totSpend),
       excel_cell('Number', $net >= 0 ? 'kpiN' : 'kpiWarn', $net),
       excel_cell('String', 'kpiPct', $totPromised > 0 ? $pct . '%' : '—'),
   ], 28) ?>
   <?= excel_empty(6) ?>
   <?= excel_row([excel_cell('String', 'sub', $scope !== '' ? $scope . '. Figures match the event ledger in EventGrant.' : 'Figures match the event ledger in EventGrant. Promised = company commitments. Received = posted receipts. Spend = approved PO / ECM lines only.')], 32) ?>
   <?= excel_empty(6) ?>
   <Row ss:Height="20"><Cell ss:MergeAcross="5" ss:StyleID="brass"><Data ss:Type="String"><?= excel_esc('Authenticated export  ·  ' . $docId . '  ·  Seal ' . $stamp) ?></Data></Cell></Row>
   <Row ss:Height="28"><Cell ss:MergeAcross="5" ss:StyleID="foot"><Data ss:Type="String"><?= excel_esc('Prepared by ' . $who . ($email ? '  ·  ' . $email : '') . '  ·  ' . $roleLabel . '. Download again from EventGrant if figures change. Do not treat an edited copy as official.') ?></Data></Cell></Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <Header x:Margin="0.3" x:Data="&amp;L<?= excel_esc($hospital) ?>&amp;CEventGrant report&amp;R<?= excel_esc($docId) ?>"/>
    <Footer x:Margin="0.3" x:Data="&amp;LConfidential&amp;CPage &amp;P of &amp;N&amp;RGenerated <?= excel_esc($when) ?>"/>
   </PageSetup>
   <FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight></Print>
   <Selected/>
  </WorksheetOptions>
 </Worksheet>

 <Worksheet ss:Name="Event ledger">
  <Table ss:ExpandedColumnCount="9" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="88"/><Column ss:Width="220"/><Column ss:Width="52"/><Column ss:Width="120"/>
   <Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/>
   <Row ss:Height="22"><Cell ss:MergeAcross="8" ss:StyleID="banner"><Data ss:Type="String"><?= excel_esc($hospital) ?>  ·  Event ledger  ·  <?= excel_esc($periodLabel) ?></Data></Cell></Row>
   <Row ss:Height="18"><Cell ss:MergeAcross="8" ss:StyleID="brass"><Data ss:Type="String"><?= excel_esc($docId) ?>  ·  Prepared by <?= excel_esc($who) ?>  ·  <?= excel_esc($when) ?>  ·  <?= excel_esc($unit) ?></Data></Cell></Row>
   <?= excel_empty(9) ?>
   <?= excel_row([
       excel_cell('String', 'th', 'Code'),
       excel_cell('String', 'th', 'Programme'),
       excel_cell('String', 'th', 'Unit'),
       excel_cell('String', 'th', 'Dates / status'),
       excel_cell('String', 'thR', 'Promised'),
       excel_cell('String', 'thR', 'Received'),
       excel_cell('String', 'thR', 'To collect'),
       excel_cell('String', 'thR', 'Spend'),
       excel_cell('String', 'thR', 'Net'),
   ], 22) ?>
<?php
$i = 0;
foreach ($rows as $row) {
    $ev = $row['event'];
    $alt = $i % 2 === 1;
    $td = $alt ? 'tdAlt' : 'td';
    $inr = $alt ? 'inrAlt' : 'inr';
    $netStyle = $row['net'] >= 0 ? 'pos' : 'neg';
    $dates = dmy($ev['start_date']) . ($ev['end_date'] !== $ev['start_date'] ? ' – ' . dmy($ev['end_date']) : '') . ' · ' . ucfirst((string) $ev['status']);
    echo excel_row([
        excel_cell('String', $td, $ev['code']),
        excel_cell('String', $td, $ev['title']),
        excel_cell('String', $td, $ev['unit_code'] ?? ''),
        excel_cell('String', $td, $dates),
        $row['unsponsored'] ? excel_cell('String', 'dash', '—') : excel_cell('Number', $inr, $row['promised']),
        $row['unsponsored'] ? excel_cell('String', 'dash', '—') : excel_cell('Number', $inr, $row['received']),
        $row['unsponsored'] ? excel_cell('String', 'dash', '—') : excel_cell('Number', $inr, $row['gap']),
        excel_cell('Number', $inr, $row['spend']),
        excel_cell('Number', $netStyle, $row['net']),
    ], 20);
    $i++;
}
?>
   <?= excel_row([
       excel_cell('String', 'tot', 'TOTAL'),
       excel_cell('String', 'tot', count($rows) . ' programmes'),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('Number', 'totN', $totPromised),
       excel_cell('Number', 'totN', $totReceived),
       excel_cell('Number', 'totN', $toCollect),
       excel_cell('Number', 'totN', $totSpend),
       excel_cell('Number', 'totN', $net),
   ], 22) ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <Header x:Margin="0.25" x:Data="&amp;L<?= excel_esc($hospital) ?>&amp;R<?= excel_esc($docId) ?>"/>
    <Footer x:Margin="0.25" x:Data="&amp;LConfidential — hospital use only&amp;C&amp;P / &amp;N&amp;R<?= excel_esc($who) ?>"/>
   </PageSetup>
   <FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><Gridlines/></Print>
   <FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane>
  </WorksheetOptions>
 </Worksheet>

 <Worksheet ss:Name="Collections">
  <Table ss:ExpandedColumnCount="5" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="240"/><Column ss:Width="110"/><Column ss:Width="110"/><Column ss:Width="110"/><Column ss:Width="90"/>
   <Row ss:Height="22"><Cell ss:MergeAcross="4" ss:StyleID="banner"><Data ss:Type="String">Collections by company  ·  <?= excel_esc($periodLabel) ?></Data></Cell></Row>
   <?= excel_empty(5) ?>
   <?= excel_row([
       excel_cell('String', 'th', 'Sponsor'),
       excel_cell('String', 'thR', 'Promised'),
       excel_cell('String', 'thR', 'Received'),
       excel_cell('String', 'thR', 'Balance'),
       excel_cell('String', 'thR', 'Collected %'),
   ], 22) ?>
<?php
$i = 0;
$sumP = $sumR = 0.0;
foreach ($sponsors as $s) {
    $p = (float) $s['promised'];
    $r = (float) $s['received'];
    $sumP += $p;
    $sumR += $r;
    $pc = $p > 0 ? round($r / $p * 100, 1) : 0;
    $alt = $i % 2 === 1;
    $td = $alt ? 'tdAlt' : 'td';
    $inr = $alt ? 'inrAlt' : 'inr';
    echo excel_row([
        excel_cell('String', $td, $s['name'] . (!empty($s['event_code']) ? ' · ' . $s['event_code'] : '')),
        excel_cell('Number', $inr, $p),
        excel_cell('Number', $inr, $r),
        excel_cell('Number', $inr, max(0, $p - $r)),
        excel_cell('Number', $alt ? 'pctAlt' : 'pct', $pc),
    ], 20);
    $i++;
}
if (!$sponsors) {
    echo excel_row([excel_cell('String', 'td', 'No sponsor promises in this period.')]);
}
?>
   <?= excel_row([
       excel_cell('String', 'tot', 'TOTAL'),
       excel_cell('Number', 'totN', $sumP ?: $totPromised),
       excel_cell('Number', 'totN', $sumR ?: $totReceived),
       excel_cell('Number', 'totN', max(0, ($sumP ?: $totPromised) - ($sumR ?: $totReceived))),
       excel_cell('String', 'tot', $totPromised > 0 ? $pct . '%' : '—'),
   ], 22) ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup><Layout x:Orientation="Landscape"/></PageSetup>
  </WorksheetOptions>
 </Worksheet>

 <Worksheet ss:Name="Spend by category">
  <Table ss:ExpandedColumnCount="3" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="240"/><Column ss:Width="120"/><Column ss:Width="90"/>
   <Row ss:Height="22"><Cell ss:MergeAcross="2" ss:StyleID="banner"><Data ss:Type="String"><?= excel_esc($spendLabel) ?> by category  ·  <?= excel_esc($periodLabel) ?></Data></Cell></Row>
   <?= excel_empty(3) ?>
   <?= excel_row([
       excel_cell('String', 'th', 'Category'),
       excel_cell('String', 'thR', 'Amount'),
       excel_cell('String', 'thR', 'Share %'),
   ], 22) ?>
<?php
$i = 0;
foreach ($cats as $c) {
    $amt = (float) $c['total'];
    $pc = $totSpend > 0 ? round($amt / $totSpend * 100, 1) : 0;
    $alt = $i % 2 === 1;
    echo excel_row([
        excel_cell('String', $alt ? 'tdAlt' : 'td', $c['name']),
        excel_cell('Number', $alt ? 'inrAlt' : 'inr', $amt),
        excel_cell('Number', $alt ? 'pctAlt' : 'pct', $pc),
    ], 20);
    $i++;
}
if (!$cats) {
    echo excel_row([excel_cell('String', 'td', 'No approved expenses in this period.')]);
}
?>
   <?= excel_row([
       excel_cell('String', 'tot', 'TOTAL'),
       excel_cell('Number', 'totN', $totSpend),
       excel_cell('String', 'tot', $totSpend > 0 ? '100%' : '—'),
   ], 22) ?>
  </Table>
 </Worksheet>
<?php if ($outstandingLines): ?>
 <Worksheet ss:Name="Outstanding">
  <Table ss:ExpandedColumnCount="6" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="88"/><Column ss:Width="200"/><Column ss:Width="200"/><Column ss:Width="110"/><Column ss:Width="110"/><Column ss:Width="110"/>
   <Row ss:Height="22"><Cell ss:MergeAcross="5" ss:StyleID="banner"><Data ss:Type="String">Outstanding sponsorships  ·  <?= excel_esc($periodLabel) ?></Data></Cell></Row>
   <?= excel_empty(6) ?>
   <?= excel_row([
       excel_cell('String', 'th', 'Event'),
       excel_cell('String', 'th', 'Programme'),
       excel_cell('String', 'th', 'Sponsor'),
       excel_cell('String', 'thR', 'Promised'),
       excel_cell('String', 'thR', 'Received'),
       excel_cell('String', 'thR', 'Outstanding'),
   ], 22) ?>
<?php
$i = 0;
$sumO = 0.0;
foreach ($outstandingLines as $o) {
    $sumO += (float) $o['outstanding'];
    $alt = $i % 2 === 1;
    $td = $alt ? 'tdAlt' : 'td';
    $inr = $alt ? 'inrAlt' : 'inr';
    echo excel_row([
        excel_cell('String', $td, $o['code']),
        excel_cell('String', $td, $o['event_title']),
        excel_cell('String', $td, $o['sponsor_name']),
        excel_cell('Number', $inr, $o['promised_amount']),
        excel_cell('Number', $inr, $o['received']),
        excel_cell('Number', $inr, $o['outstanding']),
    ], 20);
    $i++;
}
?>
   <?= excel_row([
       excel_cell('String', 'tot', 'TOTAL'),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('Number', 'totN', $sumO),
   ], 22) ?>
  </Table>
 </Worksheet>
<?php endif; ?>
<?php if ($lines): ?>
 <Worksheet ss:Name="<?= $book === 'ecm' ? 'ECM lines' : ($book === 'purchase' ? 'Purchase lines' : 'Expense lines') ?>">
  <Table ss:ExpandedColumnCount="8" x:FullColumns="1" x:FullRows="1">
   <Column ss:Width="88"/><Column ss:Width="90"/><Column ss:Width="200"/><Column ss:Width="80"/><Column ss:Width="140"/><Column ss:Width="140"/><Column ss:Width="140"/><Column ss:Width="110"/>
   <Row ss:Height="22"><Cell ss:MergeAcross="7" ss:StyleID="banner"><Data ss:Type="String"><?= excel_esc($spendLabel) ?>  ·  <?= excel_esc($periodLabel) ?></Data></Cell></Row>
   <?= excel_empty(8) ?>
   <?= excel_row([
       excel_cell('String', 'th', 'Event'),
       excel_cell('String', 'th', 'Date'),
       excel_cell('String', 'th', 'Item'),
       excel_cell('String', 'th', 'Booked as'),
       excel_cell('String', 'th', 'Ref'),
       excel_cell('String', 'th', 'Category'),
       excel_cell('String', 'th', 'Vendor'),
       excel_cell('String', 'thR', 'Amount'),
   ], 22) ?>
<?php
$i = 0;
$sumL = 0.0;
foreach ($lines as $ex) {
    $sumL += (float) $ex['amount'];
    $alt = $i % 2 === 1;
    $td = $alt ? 'tdAlt' : 'td';
    $inr = $alt ? 'inrAlt' : 'inr';
    echo excel_row([
        excel_cell('String', $td, $ex['code']),
        excel_cell('String', $td, dmy($ex['expense_date'])),
        excel_cell('String', $td, $ex['title']),
        excel_cell('String', $td, ($ex['booking_type'] ?? '') === 'ecm' ? 'ECM' : 'Purchase'),
        excel_cell('String', $td, expense_ref($ex)),
        excel_cell('String', $td, $ex['cat']),
        excel_cell('String', $td, $ex['vendor'] ?? ''),
        excel_cell('Number', $inr, $ex['amount']),
    ], 20);
    $i++;
}
?>
   <?= excel_row([
       excel_cell('String', 'tot', 'TOTAL'),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', count($lines) . ' lines'),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('String', 'tot', ''),
       excel_cell('Number', 'totN', $sumL),
   ], 22) ?>
  </Table>
 </Worksheet>
<?php endif; ?>
</Workbook>
<?php
    exit;
}
