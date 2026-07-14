<?php
echo 'Z exists: ';
var_dump(is_dir('Z:/'));

echo '<br><br>Scandir Z:<br>';
var_dump(@scandir('Z:/'));
