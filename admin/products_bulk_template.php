<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="ianova_products_template.csv"');
echo "name,slug,price,original_price,stock,description,emoji,bg_color,is_flash_deal,image_url,image_filename\n";
echo "Wireless Mouse,wireless-mouse,1500,2000,50,Compact wireless mouse,🖱️,#EFF3EC,0,,mouse.jpg\n";
echo "USB-C Cable,usb-c-cable,300,,200,1 meter fast charging cable,🔌,#EFF3EC,1,https://example.com/cable.jpg,\n";
