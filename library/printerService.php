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

    // Clean Code: No more magic numbers
    const DEFAULT_PORT = 9100;
    const DEFAULT_TIMEOUT = 8;

    public function __construct($type, $path, $charLimit = 32, $port = self::DEFAULT_PORT, $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->charLimit = $charLimit;
        try {
            if ($type === 'network' || $type === 'lan') { 
                $this->connector = new NetworkPrintConnector($path, $port, $timeout);
            } elseif ($type === 'usb' || $type === 'windows') {
                $this->connector = new WindowsPrintConnector($path);
            } else {
                throw new Exception("Unsupported printer type: " . $type);
            }

            if ($this->connector) {
                $this->printer = new Printer($this->connector);
            }
        } catch (Exception $e) {
            // Clean Code: Actually log the exception for server debugging
            error_log("Printer Connection Error: " . $e->getMessage());
            $this->printer = null;
            throw new Exception($e->getMessage());
        }
    }

    // Clean Code: UTF-8 safe length checking
    private function getLength($string) {
        return mb_strlen((string)$string, 'UTF-8');
    }

    private function columnize($left, $right) {
        $maxLeft = $this->charLimit - $this->getLength($right) - 1; 
        if ($this->getLength($left) > $maxLeft) {
            $left = mb_substr((string)$left, 0, $maxLeft, 'UTF-8'); 
        }
        $spaces = $this->charLimit - $this->getLength($left) - $this->getLength($right);
        return $left . str_repeat(" ", max(1, $spaces)) . $right . "\n";
    }

    // Clean Code: Self-documenting hardware commands
    private function beep() {
        if ($this->connector) {
            try { $this->connector->write("\x1b\x42\x02\x02"); } 
            catch (Exception $e) { error_log("Beep Error: " . $e->getMessage()); }
        }
    }

    private function kickDrawer() {
        if ($this->printer) {
            try { $this->printer->pulse(0, 120, 240); } 
            catch (Exception $e) { error_log("Drawer Kick Error: " . $e->getMessage()); }
        }
    }

    /**
     * MAIN CONTROLLER: Clean, readable, and delegates tasks.
     */
    public function printTicket($title, $items, $meta = [], $showPrice = true, $options = [])
    {
        if (!$this->printer) throw new Exception("Printer not connected.");

        $this->printer->initialize();
        if (($options['beep'] ?? 0) == 1) $this->beep();

        // Execution Flow
        $this->printHeader($title, $meta, $showPrice);
        $this->printOrderDetails($meta);
        $totals = $this->printItems($items, $showPrice);
        
        if ($showPrice) {
            $this->printTotals($totals['total'], $totals['count'], $meta, $title);
            $this->printFooter($meta, $title);
        } else {
            $this->printer->feed(3);
        }

        // Finish & Cleanup
        if (($options['cut'] ?? 0) == 1) {
            $this->printer->cut();
        } else {
            $this->printer->feed(3);
        }

        if (($options['beep'] ?? 0) == 1) $this->beep();
        if ($showPrice && $title === "RECEIPT") $this->kickDrawer();
        
        $this->printer->close();
    }

    /* =========================================================================
       COMPONENT METHODS (Separation of Concerns)
       ========================================================================= */

    private function printHeader($title, $meta, $showPrice) {
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);

        if ($showPrice) {
            try {
                if (file_exists(__DIR__ . "/../assets/img/print.png")) {
                    $logo = EscposImage::load(__DIR__ . "/../assets/img/print.png");
                    $this->printer->bitImage($logo, 0); 
                    $this->printer->feed(1);
                }
            } catch (Exception $e) {
                error_log("Logo Print Error: " . $e->getMessage());
            }

            // Clean Code: No hardcoded defaults, falls back gracefully
            $storeName = $meta['Store'] ?? "";
            if ($storeName) {
                $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
                $this->printer->text($storeName . "\n");
                $this->printer->selectPrintMode(Printer::MODE_FONT_A);
            }
            
            $tax_status = $meta['TaxStatus'] ?? "NON-VAT Reg.";
            $tin = $meta['TIN'] ?? "TIN: 000-000-000-000";
            
            $this->printer->setEmphasis(true);
            $this->printer->text($tax_status . "\n"); 
            $this->printer->setEmphasis(false);
            $this->printer->text($tin . "\n");

            if (!empty($meta['Address'])) $this->printer->text($meta['Address'] . "\n");
            if (!empty($meta['Phone'])) $this->printer->text("Tel: " . $meta['Phone'] . "\n");
            $this->printer->feed(1);
        }

        $this->printer->setEmphasis(true);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $this->printer->text(mb_strtoupper((string)$title, 'UTF-8') . "\n"); 
        $this->printer->setEmphasis(false);
        $this->printer->selectPrintMode(Printer::MODE_FONT_A);

        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
    }

    private function printOrderDetails($meta) {
        $leftHeader = "";
        $rightHeader = !empty($meta['Ref']) ? "REF#:" . $meta['Ref'] : "";
        
        if (isset($meta['Type']) && $meta['Type'] === 'TAKEOUT') {
            $leftHeader = "TAKEOUT";
        } elseif (!empty($meta['Table'])) {
            $leftHeader = "TABLE:" . $meta['Table']; 
        }
        
        if ($leftHeader || $rightHeader) {
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $doubleWidthLimit = floor($this->charLimit / 2);
            $maxLeft = $doubleWidthLimit - $this->getLength($rightHeader) - 1;
            $safeLeftHeader = $this->getLength($leftHeader) > $maxLeft ? mb_substr((string)$leftHeader, 0, $maxLeft, 'UTF-8') : $leftHeader;
            $spaces = $doubleWidthLimit - $this->getLength($safeLeftHeader) - $this->getLength($rightHeader);
            $this->printer->text($safeLeftHeader . str_repeat(" ", max(1, $spaces)) . $rightHeader . "\n");
            $this->printer->selectPrintMode(Printer::MODE_FONT_A);
        }

        $this->printer->text($this->columnize(
            "STAFF: " . ($meta['Staff'] ?? 'N/A'), 
            "TIME: " . ($meta['Date'] ?? date('H:i'))
        ));

        if (!empty($meta['Customer'])) {
            $this->printer->text("NAME: " . mb_strtoupper((string)$meta['Customer'], 'UTF-8') . "\n");
        }
        $this->printer->text(str_repeat("-", $this->charLimit) . "\n");
    }

    private function printItemModifiersAndNotes($item, $isKitchen) {
        if (!empty($item['modifiers'])) {
            foreach ($item['modifiers'] as $mod) {
                $modName = is_array($mod) ? ($mod['name'] ?? '') : $mod;
                $this->printer->text("  + " . $modName . "\n");
            }
        }
        if (!empty($item['item_notes'])) {
            if ($isKitchen) {
                $this->printer->setEmphasis(true);
                $this->printer->text("  *** NOTE: " . mb_strtoupper((string)$item['item_notes'], 'UTF-8') . " ***\n");
                $this->printer->setEmphasis(false);
            } else {
                $this->printer->text("  * Note: " . $item['item_notes'] . "\n");
            }
        }
    }

    private function printItems($items, $showPrice) {
        $total = 0;
        $itemCount = 0;

        foreach ($items as $item) {
            $qty   = (int)$item['quantity'];
            $price = (float)($item['price'] ?? 0); 
            $rawLineTotal = ($qty * $price);
            
            $priceStr = number_format($rawLineTotal, 2);
            $maxNameLen = $this->charLimit - $this->getLength($qty) - $this->getLength($priceStr) - 2;
            if ($maxNameLen < 5) $maxNameLen = 5; 
            
            $name = $item['name'];
            if ($this->getLength($name) > $maxNameLen) {
                $name = mb_substr((string)$name, 0, $maxNameLen - 2, 'UTF-8') . ".."; 
            }
            
            $total += $rawLineTotal; 
            $itemCount += $qty;
            
            if ($showPrice) {
                $this->printer->text($this->columnize($qty . " " . $name, $priceStr));
                $this->printItemModifiersAndNotes($item, false);
            } else {
                $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
                $this->printer->text($qty . "x " . $item['name'] . "\n");
                $this->printer->selectPrintMode(Printer::MODE_FONT_A);
                
                $this->printItemModifiersAndNotes($item, true);
                $this->printer->text(str_repeat("-", $this->charLimit) . "\n"); 
            }
        }
        return ['total' => $total, 'count' => $itemCount];
    }

    private function printTotals($total, $itemCount, $meta, $title) {
        $global_discount = (float)($meta['OrderDiscount'] ?? 0);
        $this->printer->text(str_repeat("-", $this->charLimit) . "\n");

        $is_sc_pwd = false;
        if (!empty($meta['SC_Records'])) {
            $is_sc_pwd = true;
        } else {
            $dl = mb_strtolower((string)($meta['DiscountLabel'] ?? ''), 'UTF-8');
            if (preg_match('/\b(sc|pwd|senior)\b/', $dl)) {
                $is_sc_pwd = true;
            }
        }

        if ($global_discount > 0) {
            if ($is_sc_pwd && isset($meta['SC_ItemCount']) && $meta['SC_ItemCount'] > 0) {
                if ($meta['Reg_ItemCount'] > 0) {
                    $this->printer->text($this->columnize($meta['Reg_ItemCount'] . " Reg Item(s)", number_format($meta['Reg_ItemTotal'], 2)));
                }
                $this->printer->text($this->columnize("Senior: " . $meta['SC_ItemCount'] . " Item(s)", number_format($meta['SC_ItemTotal'], 2)));
                $this->printer->text($this->columnize("Less " . mb_strtoupper((string)$meta['DiscountLabel'], 'UTF-8'), "-" . number_format($global_discount, 2)));
            } else {
                $this->printer->text($this->columnize($itemCount . " Item(s)", number_format($total, 2)));
                $this->printer->text($this->columnize(mb_strtoupper((string)$meta['DiscountLabel'], 'UTF-8'), "-" . number_format($global_discount, 2)));
            }
            $total -= $global_discount;
        } else {
            $this->printer->text($this->columnize($itemCount . " Item(s)", number_format($total, 2)));
        }

        $this->printer->setEmphasis(true);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        
        $doubleLimit = floor($this->charLimit / 2);
        $left = ($title === "RECEIPT") ? "TOTAL" : "TOTAL DUE";
        $right = number_format($total, 2);
        
        $maxLeft = $doubleLimit - $this->getLength($right) - 1;
        if ($this->getLength($left) > $maxLeft) {
            $left = mb_substr((string)$left, 0, $maxLeft, 'UTF-8');
        }
        
        $spaces = $doubleLimit - $this->getLength($left) - $this->getLength($right);
        $this->printer->text($left . str_repeat(" ", max(1, $spaces)) . $right . "\n");
        
        $this->printer->setEmphasis(false);
        $this->printer->selectPrintMode(Printer::MODE_FONT_A); 

        if (isset($meta['Tendered']) && isset($meta['Change'])) {
            $this->printer->text($this->columnize("CASH", number_format($meta['Tendered'], 2)));
            $this->printer->text($this->columnize("CHANGE", number_format($meta['Change'], 2)));
        }

        $this->printer->feed(1);
        $this->printer->text(str_repeat("-", $this->charLimit) . "\n");
    }

    private function printFooter($meta, $title) {
        if (!empty($meta['SC_Records'])) {
            foreach ($meta['SC_Records'] as $sc) {
                $this->printer->text($sc['discount_type'] . " Name: " . mb_strtoupper((string)$sc['person_name'], 'UTF-8') . "\n");
                $this->printer->text("Govt ID: " . mb_strtoupper((string)$sc['id_number'], 'UTF-8') . "\n");
                $addr = !empty($sc['address']) ? mb_strtoupper((string)$sc['address'], 'UTF-8') : "__________________";
                $this->printer->text("Address: " . $addr . "\n");
                $this->printer->text("Signature: ________________\n");
                $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
            }
        } elseif (preg_match('/\b(sc|pwd|senior)\b/', mb_strtolower((string)($meta['DiscountLabel'] ?? ''), 'UTF-8'))) {
            $this->printer->text("Name: ____________________\n");
            $this->printer->text("Govt ID: _________________\n");
            $this->printer->text("Address: __________________\n");
            $this->printer->text("Signature: ________________\n");
            $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
        }

        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->text("Thank you for dining with us!\n");
        
        if ($title === "RECEIPT") {
            $this->printer->text("THIS IS NOT YOUR OFFICIAL RECEIPT\n");
            $this->printer->text("THIS DOCUMENT IS NOT VALID\nFOR CLAIM OF INPUT TAXES\n");
        }
        $this->printer->feed(3);
    }
}