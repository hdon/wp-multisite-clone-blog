<?php

// http://php.net/manual/en/function.copy.php#91010
function recurse_copy($src,$dst,$hardlink=false) { 
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . '/' . $file) ) { 
                recurse_copy($src . '/' . $file,$dst . '/' . $file); 
            } 
            else { 
                if ($hardlink)
                  link($src . '/' . $file,$dst . '/' . $file); 
                else
                  copy($src . '/' . $file,$dst . '/' . $file); 
            } 
        } 
    } 
    closedir($dir); 
} 
