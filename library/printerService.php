<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

class PrinterService
{
    private $printer = null;
    private $connector = null;
    private $charLimit;

    public function __construct($type, $path, $charLimit = 32, $port = 9100)
    {
        $this->charLimit = $charLimit;
        try {
            if ($type === 'network' || $type === 'lan') { 
                $this->connector = new NetworkPrintConnector($path, $port, 3);
            } elseif ($type === 'usb' || $type === 'windows') {
                $this->connector = new WindowsPrintConnector($path);
            } else {
                throw new Exception("Unsupported printer type: " . $type);
            }

            if ($this->connector) {
                $this->printer = new Printer($this->connector);
            }
        } catch (Exception $e) {
            $this->printer = null;
            throw new Exception($e->getMessage());
        }
    }

    private function columnize($left, $right) {
        $spaces = $this->charLimit - strlen($left) - strlen($right);
        if ($spaces < 1) $spaces = 1;
        return $left . str_repeat(" ", $spaces) . $right . "\n";
    }

    public function printTicket($title, $items, $meta = [], $showPrice = true, $options = [])
    {
        if (!$this->printer) throw new Exception("Printer not connected.");

        $total = 0;

        $this->printer->initialize();
        
        if (($options['beep'] ?? 0) == 1) {
            $this->connector->write("\x1b\x42\x02\x02");
        }

        $this->printer->setJustification(Printer::JUSTIFY_CENTER);

        if ($showPrice) {
            try {
                if (file_exists(__DIR__ . "/../assets/img/print.png")) {
                    $logo = EscposImage::load(__DIR__ . "/../assets/img/print.png");
                    $this->printer->bitImage($logo, 0); 
                    $this->printer->feed(1);
                }
            } catch (Exception $e) {}

            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text(($meta['Store'] ?? "FOGS RESTAURANT") . "\n");
            $this->printer->selectPrintMode(Printer::MODE_FONT_A);
            
            if (!empty($meta['Address'])) $this->printer->text($meta['Address'] . "\n");
            if (!empty($meta['Phone'])) $this->printer->text("Tel: " . $meta['Phone'] . "\n");
            $this->printer->feed(1);
        }

        $this->printer->setEmphasis(true);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
        $this->printer->text(strtoupper($title) . "\n"); 
        $this->printer->setEmphasis(false);
        $this->printer->selectPrintMode(Printer::MODE_FONT_A);

        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text(str_repeat("=", $this->charLimit) . "\n");

        // FIX 1: Only print the ORDER # if it is an official Receipt/Bill ($showPrice = true)
        if ($showPrice && !empty($meta['Ref'])) {
            $this->printer->setEmphasis(true);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text("ORDER #: " . $meta['Ref'] . "\n");
            $this->printer->selectPrintMode(Printer::MODE_FONT_A);
            $this->printer->setEmphasis(false);
            $this->printer->text(str_repeat("-", $this->charLimit) . "\n");
        }

        if (isset($meta['Type']) && $meta['Type'] === 'TAKEOUT') {
            $this->printer->setEmphasis(true);
            $this->printer->text("TYPE:  TAKEOUT\n");
            $this->printer->setEmphasis(false);
        } elseif (!empty($meta['Table'])) {
            $this->printer->setEmphasis(true);
            $this->printer->text("TABLE: " . $meta['Table'] . "\n");
            $this->printer->setEmphasis(false);
        }

        if (!empty($meta['Customer'])) {
            $this->printer->setEmphasis(true);
            $this->printer->text("NAME:  " . strtoupper($meta['Customer']) . "\n");
            $this->printer->setEmphasis(false);
        }
        if (isset($meta['Staff'])) $this->printer->text("STAFF: " . $meta['Staff'] . "\n");
        if (isset($meta['Date']))  $this->printer->text("TIME:  " . $meta['Date'] . "\n");
        $this->printer->text(str_repeat("-", $this->charLimit) . "\n");

        foreach ($items as $item) {
            $qty   = (int)$item['quantity'];
            $name  = $item['name'];
            $price = (float)($item['price'] ?? 0); 
            $rawLineTotal = ($qty * $price);
            $total += $rawLineTotal; 
            
            if ($showPrice) {
                $this->printer->text($this->columnize($qty . "x " . $name, number_format($rawLineTotal, 2)));
                
                if (!empty($item['modifiers'])) {
                    foreach ($item['modifiers'] as $mod) {
                        $this->printer->text("  + " . ($mod['name'] ?? $mod) . "\n");
                    }
                }
            } else {
                $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
                $this->printer->text($qty . "x " . $name . "\n");
                $this->printer->selectPrintMode(Printer::MODE_FONT_A);
                if (!empty($item['modifiers'])) {
                    foreach ($item['modifiers'] as $mod) {
                        $this->printer->text("  + " . ($mod['name'] ?? $mod) . "\n");
                    }
                }
                $this->printer->text(str_repeat("-", $this->charLimit) . "\n"); 
            }
        }

        if ($showPrice) {
            $global_discount = (float)($meta['OrderDiscount'] ?? 0);
            $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
            
            if ($global_discount > 0) {
                // FIX 2: Print "Subtotal" clearly before deducting the labeled discount
                $this->printer->text($this->columnize("SUBTOTAL", number_format($total, 2)));
                
                $disc_label = !empty($meta['DiscountLabel']) ? substr($meta['DiscountLabel'], 0, 20) : "DISCOUNT";
                $this->printer->text($this->columnize($disc_label, "-" . number_format($global_discount, 2)));
                
                if (!empty($meta['OrderDiscountNote'])) {
                    $this->printer->setJustification(Printer::JUSTIFY_LEFT);
                    $notes = explode('|', $meta['OrderDiscountNote']);
                    foreach($notes as $n) {
                        $this->printer->text("  * " . trim($n) . "\n");
                    }
                }
                $total -= $global_discount;
            }

            $this->printer->setEmphasis(true);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            
            $doubleLimit = floor($this->charLimit / 2);
            $left = "TOTAL";
            $right = "P" . number_format($total, 2);
            $spaces = $doubleLimit - strlen($left) - strlen($right);
            if ($spaces < 1) $spaces = 1;
            
            $this->printer->text($left . str_repeat(" ", $spaces) . $right . "\n");
            
            $this->printer->setEmphasis(false);
            $this->printer->selectPrintMode(Printer::MODE_FONT_A); 

            if (isset($meta['Tendered']) && isset($meta['Change'])) {
                $this->printer->feed(1);
                $this->printer->text($this->columnize("TENDERED", number_format($meta['Tendered'], 2)));
                $this->printer->text($this->columnize("CHANGE", number_format($meta['Change'], 2)));
                if(isset($meta['Method'])) {
                    $this->printer->text($this->columnize("METHOD", strtoupper($meta['Method'])));
                }
                $this->printer->feed(1);
                $this->printer->setJustification(Printer::JUSTIFY_CENTER);
                $this->printer->text("Thank you for dining with us!\n");
                $this->printer->text("THIS IS NOT YOUR OFFICIAL RECEIPT!\n");
            }
        }

        $this->printer->feed(3);

        if (($options['cut'] ?? 0) == 1) {
            $this->printer->cut();
        } else {
            $this->printer->feed(3);
        }
        $this->printer->close();
    }
}