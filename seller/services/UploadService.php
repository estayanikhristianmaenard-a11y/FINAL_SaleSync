<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class UploadService {
 public function productImages(int $sellerId,array $thumbnail,array $gallery): array {
  if($sellerId<1)throw new \InvalidArgumentException('A valid seller is required');
  $files=[];
  if(isset($gallery['name'])&&is_array($gallery['name'])){
   foreach($gallery['name'] as $index=>$name)$files[]=['name'=>$name,'type'=>$gallery['type'][$index]??'','tmp_name'=>$gallery['tmp_name'][$index]??'','error'=>$gallery['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$gallery['size'][$index]??0];
  }elseif(isset($gallery['name']))$files[]=$gallery;
  $files=array_values(array_filter($files,static fn(array $file):bool=>($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE));
  if(count($files)>5)throw new \InvalidArgumentException('A product may contain at most five gallery images');
  if(($thumbnail['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE&&!$files)return ['thumbnail'=>null,'gallery_images'=>[],'absolute_paths'=>[]];
  $directory=UPLOAD_DIR.'/products/'.$sellerId;
  if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new \RuntimeException('Product upload directory unavailable');
  $result=['thumbnail'=>null,'gallery_images'=>[],'absolute_paths'=>[]];
  try {
   if(($thumbnail['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$stored=$this->saveProductImage($thumbnail,$directory,$sellerId);$result['thumbnail']=$stored['path'];$result['absolute_paths'][]=$stored['absolute_path'];}
   foreach($files as $file){$stored=$this->saveProductImage($file,$directory,$sellerId);$result['gallery_images'][]=$stored['path'];$result['absolute_paths'][]=$stored['absolute_path'];}
   return $result;
  } catch(\Throwable $error) {foreach($result['absolute_paths'] as $path)if(is_file($path))@unlink($path);throw $error;}
 }
 private function saveProductImage(array $file,string $directory,int $sellerId): array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Product image upload failed');
  $temporary=(string)($file['tmp_name']??'');if($temporary===''||!is_uploaded_file($temporary))throw new \InvalidArgumentException('Invalid uploaded product image');
  $size=filesize($temporary);if($size===false||$size<1||$size>MAX_UPLOAD_BYTES)throw new \InvalidArgumentException('Each product image must be between 1 byte and 5 MB');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);if(!isset(ALLOWED_IMAGE_MIMES[$mime]))throw new \InvalidArgumentException('Only JPG, JPEG, PNG and WebP product images are supported');
  if(@getimagesize($temporary)===false)throw new \InvalidArgumentException('Uploaded product file is not a valid image');
  $filename='product_'.bin2hex(random_bytes(20)).'.'.ALLOWED_IMAGE_MIMES[$mime];$destination=$directory.'/'.$filename;
  if(!move_uploaded_file($temporary,$destination))throw new \RuntimeException('Could not save product image');
  @chmod($destination,0640);return ['path'=>'uploads/products/'.$sellerId.'/'.$filename,'absolute_path'=>$destination];
 }
 public function image(array $file,string $prefix): string {if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Image upload failed');if(($file['size']??0)>MAX_UPLOAD_BYTES)throw new \InvalidArgumentException('Image must be 5 MB or smaller');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!isset(ALLOWED_IMAGE_MIMES[$mime]))throw new \InvalidArgumentException('Only JPG, PNG and WebP images are supported');if(!is_dir(UPLOAD_DIR)&&!mkdir(UPLOAD_DIR,0750,true)&&!is_dir(UPLOAD_DIR))throw new \RuntimeException('Upload directory unavailable');$name=$prefix.'_'.bin2hex(random_bytes(16)).'.'.ALLOWED_IMAGE_MIMES[$mime];if(!move_uploaded_file($file['tmp_name'],UPLOAD_DIR.'/'.$name))throw new \RuntimeException('Could not save uploaded image');return 'uploads/'.$name;}
 public function storeImage(array $file,string $asset): array {
  if(!in_array($asset,['logo','banner'],true))throw new \InvalidArgumentException('Unsupported store image type');
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Image upload failed');
  $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new \InvalidArgumentException('Invalid uploaded image');
  $size=filesize($tmp);if($size===false||$size<1||$size>MAX_UPLOAD_BYTES)throw new \InvalidArgumentException('Image must be no larger than 5 MB');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!isset(ALLOWED_IMAGE_MIMES[$mime]))throw new \InvalidArgumentException('Only JPG, JPEG, PNG and WebP images are supported');
  if(@getimagesize($tmp)===false)throw new \InvalidArgumentException('Uploaded file is not a valid image');
  $directory=UPLOAD_DIR.'/store';if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new \RuntimeException('Store upload directory unavailable');
  $filename=$asset.'_'.bin2hex(random_bytes(20)).'.'.ALLOWED_IMAGE_MIMES[$mime];$destination=$directory.'/'.$filename;
  if(!move_uploaded_file($tmp,$destination))throw new \RuntimeException('Could not save uploaded image');
  @chmod($destination,0640);
  return ['path'=>'uploads/store/'.$filename,'absolute_path'=>$destination,'mime_type'=>$mime,'size'=>$size];
 }
 public function returnEvidence(array $file): array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Evidence upload failed');
  $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new \InvalidArgumentException('Invalid uploaded evidence file');
  $size=filesize($tmp);if($size===false||$size<1||$size>MAX_UPLOAD_BYTES)throw new \InvalidArgumentException('Evidence images must be no larger than 5 MB');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!isset(ALLOWED_IMAGE_MIMES[$mime]))throw new \InvalidArgumentException('Only JPG, PNG and WebP evidence images are supported');
  if(@getimagesize($tmp)===false)throw new \InvalidArgumentException('Evidence file is not a valid image');
  $dir=UPLOAD_DIR.'/returns';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new \RuntimeException('Return evidence directory unavailable');
  $name='return_'.bin2hex(random_bytes(20)).'.'.ALLOWED_IMAGE_MIMES[$mime];$destination=$dir.'/'.$name;
  if(!move_uploaded_file($tmp,$destination))throw new \RuntimeException('Could not save return evidence image');
  @chmod($destination,0640);return ['path'=>'uploads/returns/'.$name,'mime_type'=>$mime];
 }
}
