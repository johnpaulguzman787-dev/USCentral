<?php
use PhpOffice\PhpWord\PhpWord;

function buildAttendanceDocx(array $data): PhpWord
{
    $title   = $data['title'] ?? 'Attendance Sheet';
    $event   = $data['event'] ?? '';
    $date    = $data['date'] ?? '';
    $venue   = $data['venue'] ?? '';
    $columns = is_array($data['columns']) ? $data['columns'] : [];

    $phpWord = new PhpWord();

    // Section
    $section = $phpWord->addSection([
        'marginTop'    => 800,
        'marginBottom' => 800,
        'marginLeft'   => 800,
        'marginRight'  => 800,
    ]);

    // HEADER
    $section->addText(
        strtoupper($title),
        ['bold' => true, 'size' => 16],
        ['alignment' => 'center']
    );

    $section->addTextBreak(1);

    // DETAILS
    if ($event)  $section->addText("Event: $event");
    if ($date)   $section->addText("Date: $date");
    if ($venue) $section->addText("Venue: $venue");

    $section->addTextBreak(1);

    // TABLE STYLE
    $table = $section->addTable([
        'borderSize'  => 8,
        'borderColor' => '000000',
        'cellMargin'  => 100
    ]);

    // HEADER ROW
    $table->addRow();
    foreach ($columns as $col) {
        $table->addCell(2000)->addText(
            $col,
            ['bold' => true],
            ['alignment' => 'center']
        );
    }

    // EMPTY ROWS (FOR MANUAL SIGNING)
    for ($i = 0; $i < 25; $i++) {
        $table->addRow();
        foreach ($columns as $col) {
            $table->addCell(2000)->addText('');
        }
    }

    return $phpWord;
}
