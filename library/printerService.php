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
            $this->printer->text(($meta['Store'] ?? "FogsTasa's Cafe") . "\n");
            $this->printer->selectPrintMode(Printer::MODE_FONT_A);
            
            $this->printer->setEmphasis(true);
            $this->printer->text("NON-VAT Reg.\n"); 
            $this->printer->setEmphasis(false);

            if (!empty($meta['Address'])) $this->printer->text($meta['Address'] . "\n");
            if (!empty($meta['Phone'])) $this->printer->text("Tel: " . $meta['Phone'] . "\n");
            $this->printer->feed(1);
        }

        $this->printer->setEmphasis(true);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
        $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $this->printer->text(strtoupper($title) . "\n"); 
        $this->printer->setEmphasis(false);
        $this->printer->selectPrintMode(Printer::MODE_FONT_A);

        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text(str_repeat("=", $this->charLimit) . "\n");

        if ($showPrice) {
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
                $spaces = $doubleWidthLimit - strlen($leftHeader) - strlen($rightHeader);
                if ($spaces < 1) $spaces = 1;
                $this->printer->text($leftHeader . str_repeat(" ", $spaces) . $rightHeader . "\n");
                $this->printer->selectPrintMode(Printer::MODE_FONT_A);
            }

            $this->printer->text($this->columnize(
                "STAFF: " . ($meta['Staff'] ?? 'N/A'), 
                "TIME: " . ($meta['Date'] ?? date('H:i'))
            ));

            if (!empty($meta['Customer'])) {
                $this->printer->text("NAME: " . strtoupper($meta['Customer']) . "\n");
            }
            $this->printer->text(str_repeat("-", $this->charLimit) . "\n");
        }

        $itemCount = 0;
        
        $is_sc_pwd = false;
        if (!empty($meta['SC_Records'])) {
            $is_sc_pwd = true;
        } else {
            $dl = strtolower($meta['DiscountLabel'] ?? '');
            if (preg_match('/\b(sc|pwd|senior)\b/', $dl)) {
                $is_sc_pwd = true;
            }
        }

        foreach ($items as $item) {
            $qty   = (int)$item['quantity'];
            $price = (float)($item['price'] ?? 0); 
            $rawLineTotal = ($qty * $price);
            
            // Dynamic Name Truncation
            $priceStr = number_format($rawLineTotal, 2);
            $maxNameLen = $this->charLimit - strlen((string)$qty) - strlen($priceStr) - 2;
            if ($maxNameLen < 5) $maxNameLen = 5; 
            $name  = substr($item['name'], 0, $maxNameLen);
            
            $total += $rawLineTotal; 
            $itemCount += $qty;
            
            if ($showPrice) {
                // REGULAR CUSTOMER RECEIPT VIEW
                $this->printer->text($this->columnize($qty . " " . $name, $priceStr));
                if (!empty($item['modifiers'])) {
                    foreach ($item['modifiers'] as $mod) {
                        $this->printer->text("  + " . ($mod['name'] ?? $mod) . "\n");
                    }
                }
                // Print note subtly on customer bill
                if (!empty($item['item_notes'])) {
                    $this->printer->text("  * Note: " . $item['item_notes'] . "\n");
                }
            } else {
                // KITCHEN TICKET VIEW
                $this->printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT);
                $this->printer->text($qty . "x " . $item['name'] . "\n");
                $this->printer->selectPrintMode(Printer::MODE_FONT_A);
                if (!empty($item['modifiers'])) {
                    foreach ($item['modifiers'] as $mod) {
                        $this->printer->text("  + " . ($mod['name'] ?? $mod) . "\n");
                    }
                }
                
                // Print Note in BOLD AND CAPS for the Chef!
                if (!empty($item['item_notes'])) {
                    $this->printer->setEmphasis(true);
                    $this->printer->text("  *** NOTE: " . strtoupper($item['item_notes']) . " ***\n");
                    $this->printer->setEmphasis(false);
                }
                
                $this->printer->text(str_repeat("-", $this->charLimit) . "\n"); 
            }
        }

        if ($showPrice) {
            $global_discount = (float)($meta['OrderDiscount'] ?? 0);
            $this->printer->text(str_repeat("-", $this->charLimit) . "\n");

            if ($global_discount > 0) {
                // FIX: Grab the pre-calculated split from print_order.php!
                if ($is_sc_pwd && isset($meta['SC_ItemCount']) && $meta['SC_ItemCount'] > 0) {
                    if ($meta['Reg_ItemCount'] > 0) {
                        $this->printer->text($this->columnize($meta['Reg_ItemCount'] . " Reg Item(s)", number_format($meta['Reg_ItemTotal'], 2)));
                    }
                    $this->printer->text($this->columnize("Senior: " . $meta['SC_ItemCount'] . " Item(s)", number_format($meta['SC_ItemTotal'], 2)));
                    $this->printer->text($this->columnize("Less " . strtoupper($meta['DiscountLabel']), "-" . number_format($global_discount, 2)));
                } else {
                    $this->printer->text($this->columnize($itemCount . " Item(s)", number_format($total, 2)));
                    $this->printer->text($this->columnize(strtoupper($meta['DiscountLabel']), "-" . number_format($global_discount, 2)));
                }
                $total -= $global_discount;
            } else {
                $this->printer->text($this->columnize($itemCount . " Item(s)", number_format($total, 2)));
            }

            $this->printer->setEmphasis(true);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            
            $doubleLimit = floor($this->charLimit / 2);
            $left = "TOTAL DUE";
            $right = number_format($total, 2);
            $spaces = $doubleLimit - strlen($left) - strlen($right);
            if ($spaces < 1) $spaces = 1;
            
            $this->printer->text($left . str_repeat(" ", $spaces) . $right . "\n");
            
            $this->printer->setEmphasis(false);
            $this->printer->selectPrintMode(Printer::MODE_FONT_A); 

            if (isset($meta['Tendered']) && isset($meta['Change'])) {
                $this->printer->text($this->columnize("CASH", number_format($meta['Tendered'], 2)));
                $this->printer->text($this->columnize("CHANGE", number_format($meta['Change'], 2)));
            }

            $this->printer->feed(1);
            $this->printer->text(str_repeat("-", $this->charLimit) . "\n");

            if ($is_sc_pwd) {
                if (!empty($meta['SC_Records'])) {
                    foreach ($meta['SC_Records'] as $sc) {
                        $this->printer->text($sc['discount_type'] . " Name: " . strtoupper($sc['person_name']) . "\n");
                        $this->printer->text("Govt ID: " . strtoupper($sc['id_number']) . "\n");
                        
                        $addr = !empty($sc['address']) ? strtoupper($sc['address']) : "__________________";
                        $this->printer->text("Address: " . $addr . "\n");
                        
                        $this->printer->text("Signature: ________________\n");
                        $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
                    }
                } 
                else {
                    $this->printer->text("Name: ____________________\n");
                    $this->printer->text("Govt ID: _________________\n");
                    $this->printer->text("Address: __________________\n");
                    $this->printer->text("Signature: ________________\n");
                    $this->printer->text(str_repeat("=", $this->charLimit) . "\n");
                }
            }

            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("Thank you for dining with us!\n");
            
            if ($title === "RECEIPT") {
                $this->printer->text("THIS IS NOT YOUR OFFICIAL RECEIPT!\n");
            }
        }

        $this->printer->feed(3);

        if (($options['cut'] ?? 0) == 1) {
            $this->printer->cut();
        } else {
            $this->printer->feed(3);
        }

        if (($options['beep'] ?? 0) == 1) {
            $this->connector->write("\x1b\x42\x02\x02");
        }
        $this->printer->close();
    }
}