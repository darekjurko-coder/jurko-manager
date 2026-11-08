<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../server/database.php';
$file=null;$handle=null;$db=null;
try{
    umask(0077);$dir=getenv('HOME').'/jurko-private/backups';
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException();
    $file=$dir.'/mysql-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.sql';
    $handle=fopen($file,'x');if(!$handle)throw new RuntimeException();
    $write=static function(string $text)use($handle):void{if(fwrite($handle,$text)!==strlen($text))throw new RuntimeException();};
    $db=jurkoDatabase();$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach($tables as $table){
        if(!preg_match('/^jm_[a-z_]+$/D',$table))continue;
        $definition=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
        $write($definition[1].";\n");
        $rows=$db->query('SELECT * FROM `'.$table.'`');
        while($row=$rows->fetch(PDO::FETCH_NUM)){
            $values=array_map(static fn($v)=>$v===null?'NULL':$db->quote((string)$v),$row);
            $write('INSERT INTO `'.$table.'` VALUES ('.implode(',',$values).");\n");
        }
    }
    $write("SET FOREIGN_KEY_CHECKS=1;\n");$db->commit();
    if(!fflush($handle))throw new RuntimeException();fclose($handle);$handle=null;
    echo "PRIVATE_DATABASE_BACKUP_OK\n";
}catch(Throwable $e){
    if($db instanceof PDO&&$db->inTransaction())$db->rollBack();
    if(is_resource($handle))fclose($handle);
    if($file&&is_file($file))unlink($file);
    fwrite(STDERR,"Kopia bazy nie została zakończona. Wdrożenie zatrzymane przed migracją.\n");exit(1);
}
