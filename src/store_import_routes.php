<?php
if($page==='store-import'){
    if(!Access::atLeast($user,'admin')){http_response_code(403);$page='forbidden';}
    else{
        require_once __DIR__.'/StoreImport.php';
        $importer=new StoreImport($project);
        if(($_GET['sample']??'')==='1'){
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="store-import-sample.csv"');
            $output=fopen('php://output','w');
            fputcsv($output,StoreImport::FIELDS,',','"','');
            fputcsv($output,['STORE-001','Example store','0100','Example city','10 Example Street','','','','','','','','','','','1'],',','"','');fclose($output);exit;
        }
        if($isPost){
            try{
                $action=$_POST['action']??'';
                if($action==='preview'){
                    unset($_SESSION['store_import']);
                    $file=$_FILES['csv']??[];
                    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>2*1024*1024)throw new DomainException('Choose a CSV file up to 2 MB.');
                    $_SESSION['store_import']=$importer->preview($user,file_get_contents($file['tmp_name']));
                    redirect('store-import');
                }elseif($action==='confirm'){
                    $draft=$_SESSION['store_import']??[];unset($_SESSION['store_import']);
                    $count=$importer->apply($user,$draft,ProjectRepository::text($_POST,'token',48,true));
                    $_SESSION['flash']=$count.' stores imported successfully.';redirect('store-import');
                }elseif($action==='cancel'){unset($_SESSION['store_import']);redirect('store-import');}
                else throw new DomainException('Invalid import action.');
            }catch(DomainException $exception){$error=$exception->getMessage();}
            catch(Throwable $exception){error_log((string)$exception);$error='Import failed. No changes were saved. Please upload again.';}
        }
        $importDraft=$_SESSION['store_import']??null;
    }
}
