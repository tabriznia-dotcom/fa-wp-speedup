<?php
/** Disposable-site integration tests for image derivatives and asset guards. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:8080' !== home_url() ) {
	throw new RuntimeException( 'Disposable site required' );
}
global $faws_checks;
$faws_checks = 0;
function faws_new_check( $ok, $label ) {
	global $faws_checks;
	++$faws_checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
}
function faws_test_image( $name, $blue = false ) {
	$old = get_posts( array( 'post_type'=>'attachment', 'post_status'=>'inherit', 'name'=>$name, 'numberposts'=>1 ) );
	if ( $old ) { return $old[0]->ID; }
	$uploads = wp_upload_dir();
	$path = $uploads['path'] . '/' . $name . '.jpg';
	$canvas = imagecreatetruecolor(1200,800);
	for($line=0;$line<800;$line+=4){
		$color=imagecolorallocate($canvas, $blue ? 50 : ($line % 255), ($line*2)%255, $blue ? ($line%255) : 80);
		imagefilledrectangle($canvas,0,$line,1199,$line+3,$color);
	}
	for($x=40;$x<1200;$x+=75){imagefilledellipse($canvas,$x,400,120,250,imagecolorallocate($canvas,($x*3)%255,90,($x*7)%255));}
	imagejpeg($canvas,$path,97);imagedestroy($canvas);
	$id=wp_insert_attachment(array('post_title'=>$name,'post_name'=>$name,'post_status'=>'inherit','post_mime_type'=>'image/jpeg'),$path);
	require_once ABSPATH.'wp-admin/includes/image.php';
	wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$path));
	return $id;
}
function faws_complete( $id, $format='webp' ) {
	if(!FAWS_Media::queue($id,$format,80)){throw new RuntimeException('Codec or source unavailable');}
	$consent=get_post_meta($id,'_faws_media_consent',true);
	for($n=0;$n<8;$n++){
		FAWS_Media::worker($id,$consent['token']);
		$job=get_post_meta($id,'_faws_media_job',true);
		if($n===0){faws_new_check($job['cursor']<=2,'worker batch bounded to two');}
		if($job['status']==='complete'){return $job;}
		if($job['status']!=='queued'){throw new RuntimeException('Worker error: '.$job['status']);}
	}
	throw new RuntimeException('Job did not complete within bounded batches');
}
wp_set_current_user(0);
$_GET=array(); $_COOKIE=array();
$id=faws_test_image('faws-media-main');
$variant_id=faws_test_image('faws-media-variation',true);
$png_posts=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','name'=>'faws-media-alpha','numberposts'=>1));
if($png_posts){$png_id=$png_posts[0]->ID;}else{
 $u=wp_upload_dir();$png=$u['path'].'/faws-media-alpha.png';$canvas=imagecreatetruecolor(300,180);imagealphablending($canvas,false);imagesavealpha($canvas,true);
 imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));
 for($y=30;$y<150;$y++){imagefilledrectangle($canvas,30,$y,270,$y,imagecolorallocatealpha($canvas,$y,120,200,0));}
 imagepng($canvas,$png,0);imagedestroy($canvas);
 $png_id=wp_insert_attachment(array('post_title'=>'faws-media-alpha','post_name'=>'faws-media-alpha','post_status'=>'inherit','post_mime_type'=>'image/png'),$png);
 require_once ABSPATH.'wp-admin/includes/image.php';wp_update_attachment_metadata($png_id,wp_generate_attachment_metadata($png_id,$png));
}

$meta=wp_get_attachment_metadata($id);
$original=get_attached_file($id);
$original_hash=hash_file('sha256',$original);
$old_media=get_option('faws_media_options',array());
$old_advanced=get_option('faws_advanced',array());
try{
	FAWS_Media::revoke($id,true);
	delete_option('faws_media_options');
	$before=wp_get_attachment_image($id,'large');
	faws_new_check(!str_contains($before,'/faws-media/'),'defaults preserve original image');
	faws_new_check(!FAWS_Media::queue($id,'svg',80),'unsupported format rejected');
	$job=faws_complete($id);
	faws_new_check(!empty($job['files']),'smaller WebP derivatives produced');
	faws_new_check(wp_get_attachment_metadata($id)===$meta && hash_file('sha256',$original)===$original_hash,'original and metadata unchanged');
	faws_new_check(!str_contains(wp_get_attachment_image($id,'large'),'/faws-media/'),'generation alone does not enable serving');
	faws_new_check(FAWS_Media::activate($id),'completed WebP separately approved');
	faws_new_check(!str_contains(wp_get_attachment_image($id,'large'),'/faws-media/'),'global opt-in required');
	update_option('faws_media_options',array('webp'=>true,'dimensions'=>true,'priority'=>true));
	$html=wp_get_attachment_image($id,'large');
	faws_new_check(str_contains($html,'/faws-media/') && str_contains($html,'srcset=') && str_contains($html,'sizes='),'WebP and responsive markup retained');

	$conflict=static function(){return array('imagify/imagify.php');};add_filter('pre_option_active_plugins',$conflict);
	faws_new_check(!str_contains(wp_get_attachment_image($id,'large'),'/faws-media/'),'second image optimizer blocks duplicate serving layer');remove_filter('pre_option_active_plugins',$conflict);
	faws_new_check(str_contains($html,'width="1024"') && str_contains($html,'height="683"'),'native large dimensions retained');
	preg_match('/src="([^"]+)"/',$html,$match);
	$main_url=html_entity_decode($match[1]);
	$file=wp_upload_dir()['basedir'].'/faws-media/'.wp_basename($main_url);
	$bytes=file_get_contents($file); file_put_contents($file,'corrupt');clearstatcache();
	$html=wp_get_attachment_image($id,'large');preg_match('/src="([^"]+)"/',$html,$match);
	faws_new_check(!str_contains($match[1],'/faws-media/'),'corrupt derivative falls back to original');
	faws_new_check(!FAWS_Media::activate($id),'activation rechecks integrity rather than trusting stale job status');
	file_put_contents($file,$bytes);clearstatcache();
	wp_set_current_user(1);
	faws_new_check(!str_contains(wp_get_attachment_image($id,'large'),'/faws-media/'),'authenticated image bypass');
	wp_set_current_user(0);
	FAWS_Media::revoke($id);
	faws_new_check(!str_contains(wp_get_attachment_image($id,'large'),'/faws-media/') && is_file($file),'rollback preserves derivative while restoring original');

	$first_files=get_post_meta($id,'_faws_media_files',true);
	$second=faws_complete($id);faws_new_check(FAWS_Media::activate($id),'new WebP generation separately activated');
	$previous=get_post_meta($id,'_faws_media_previous_files',true);
	faws_new_check($previous===$first_files,'previous generation retained after successful activation');
	FAWS_Media::activate($id);
	faws_new_check($previous===get_post_meta($id,'_faws_media_previous_files',true),'repeated activation is idempotent');
	$third=faws_complete($id);FAWS_Media::activate($id);
	list($cache)=FAWS_Media::directory();
	faws_new_check(!is_file($cache.'/'.reset($first_files)['file']) && is_file($cache.'/'.reset($second['files'])['file']),'retention keeps newest two generations');
	$avif=faws_complete($id,'avif');
	faws_new_check(!empty($avif['files']) && !FAWS_Media::activate($id),'AVIF preview generated but direct serving refused');
	FAWS_Media::revoke($id,true);
	faws_new_check(hash_file('sha256',$original)===$original_hash && !is_file($file),'owned-file cleanup preserves original');
	faws_new_check(FAWS_Media::queue($id,'webp',80),'approved job queued');
	$consent=get_post_meta($id,'_faws_media_consent',true);FAWS_Media::revoke($id,true);
	FAWS_Media::worker($id,$consent['token']);
	faws_new_check(!get_post_meta($id,'_faws_media_job',true) && !get_post_meta($id,'_faws_media_consent',true),'revoked job cannot resurrect consent or output');

	$png_hash=hash_file('sha256',get_attached_file($png_id));$png_job=faws_complete($png_id);FAWS_Media::activate($png_id);
	$entry=reset($png_job['files']);list($cache)=FAWS_Media::directory();$decoded=imagecreatefromwebp($cache.'/'.$entry['file']);
	faws_new_check(((imagecolorat($decoded,0,0)>>24)&127)===127,'transparent PNG alpha preserved in WebP');imagedestroy($decoded);
	$raw=file_get_contents(get_attached_file($png_id));$chunk=pack('NN',1,0);$chunk=pack('N',8).'acTL'.$chunk.pack('N',crc32('acTL'.$chunk));
	$path=wp_upload_dir()['path'].'/faws-test-animation.png';file_put_contents($path,substr($raw,0,33).$chunk.substr($raw,33));
	$apng=wp_insert_attachment(array('post_title'=>'faws-test-animation','post_status'=>'inherit','post_mime_type'=>'image/png'),$path);
	wp_update_attachment_metadata($apng,array('file'=>_wp_relative_upload_path($path),'width'=>300,'height'=>180,'sizes'=>array()));
	faws_new_check(!FAWS_Media::queue($apng,'webp',80),'APNG is not silently converted to a static image');wp_delete_attachment($apng,true);
	FAWS_Media::cleanup(true);
	faws_new_check(!get_post_meta($png_id,'_faws_media_job',true)&&hash_file('sha256',get_attached_file($png_id))===$png_hash,'paged uninstall cleanup includes inherited attachments and preserves originals');

	FAWS_Media::queue($id,'webp',80);$pending=get_post_meta($id,'_faws_media_consent',true);
	$cancel=static function($formats)use($id){FAWS_Media::revoke($id,true);return $formats;};add_filter('image_editor_output_format',$cancel);
	FAWS_Media::worker($id,$pending['token']);remove_filter('image_editor_output_format',$cancel);
	faws_new_check(!get_post_meta($id,'_faws_media_consent',true)&&!get_post_meta($id,'_faws_media_job',true),'cancellation during encoding cannot restore consent or job state');
	$png_job=faws_complete($png_id);$png_entry=reset($png_job['files']);list($cache)=FAWS_Media::directory();
	FAWS_Media::deleted($png_id);
	faws_new_check(!is_file($cache.'/'.$png_entry['file'])&&is_file(get_attached_file($png_id)),'attachment deletion hook cleans derivatives without deleting source itself');
	// Real dependency graphs protect transitive consumers and cycles.
	wp_enqueue_script('sample-leaf',home_url('/uploads/leaf.js'),array(),'1');
	faws_new_check(''===FAWS_Advanced::guard(wp_scripts(),'sample-leaf'),'local independent resource eligible');
	wp_enqueue_script('sample-dependency',home_url('/uploads/dependency.js'),array(),'1');
	wp_enqueue_script('sample-middle',home_url('/uploads/middle.js'),array('sample-dependency'),'1');
	wp_enqueue_script('sample-root',home_url('/uploads/root.js'),array('sample-middle'),'1');
	faws_new_check('required_by_another_resource'===FAWS_Advanced::guard(wp_scripts(),'sample-dependency'),'transitive dependencies protected');
	wp_add_inline_script('sample-leaf','window.keepThis=true;');
	faws_new_check('inline_or_localized_or_special'===FAWS_Advanced::guard(wp_scripts(),'sample-leaf'),'inline data protected');
	wp_enqueue_script('custom-payment',home_url('/uploads/payment.js'));
	faws_new_check('protected_critical_resource'===FAWS_Advanced::guard(wp_scripts(),'custom-payment'),'payment handle protected');
	wp_enqueue_script('sample-remote','https://example.invalid/lib.js');
	faws_new_check('external_or_dynamic_resource'===FAWS_Advanced::guard(wp_scripts(),'sample-remote'),'external source protected');
	wp_enqueue_script('sample-key',home_url('/uploads/data.js?nonce=secret'));
	faws_new_check('external_or_dynamic_resource'===FAWS_Advanced::guard(wp_scripts(),'sample-key'),'dynamic query protected');
	faws_new_check(array()===FAWS_Advanced::validate_rules('[{"type":"style","handle":"x","context":"home","action":"defer"}]'),'style defer rejected');
	faws_new_check(array()===FAWS_Advanced::validate_rules('[{"type":"script","handle":"*","context":"home","action":"unload"}]'),'wildcard handle rejected');
	faws_new_check(1===count(FAWS_Advanced::validate_rules('[{"type":"script","handle":"sample","context":"home","action":"defer"}]')),'exact valid rule accepted');
	update_option('faws_advanced',array('mode'=>'live','baseline'=>false,'confirmed'=>true));
	faws_new_check(!FAWS_Advanced::eligible(),'production mode requires baseline confirmation');

	update_option('faws_advanced',array('mode'=>'live','baseline'=>true,'confirmed'=>true));
	$conflict=static function(){return array('autoptimize/autoptimize.php');};add_filter('pre_option_active_plugins',$conflict);
	faws_new_check(!FAWS_Advanced::eligible(),'asset optimizer conflict blocks public rules');remove_filter('pre_option_active_plugins',$conflict);
	wp_enqueue_script('cycle-a',home_url('/uploads/a.js'),array('cycle-b'));wp_enqueue_script('cycle-b',home_url('/uploads/b.js'),array('cycle-a'));
	wp_enqueue_script('sample-independent',home_url('/uploads/independent.js'));
	faws_new_check(''===FAWS_Advanced::guard(wp_scripts(),'sample-independent'),'cyclic unrelated dependencies terminate safely');
	WP_CLI::success($faws_checks.' image/advanced integration checks passed');
}finally{
	update_option('faws_media_options',$old_media);update_option('faws_advanced',$old_advanced);
	FAWS_Media::revoke($id,true);FAWS_Media::revoke($variant_id,true);
}
// Leave two approved fixtures for subsequent browser variation and audit tests.
faws_complete($id);faws_complete($variant_id);FAWS_Media::activate($id);FAWS_Media::activate($variant_id);
update_option('faws_media_options',array('webp'=>true,'priority'=>true,'dimensions'=>true,'audit'=>true));
$simple=wc_get_product(wc_get_product_id_by_sku('faws-simple'));$simple->set_image_id($id);$simple->save();
$variable=wc_get_product(wc_get_product_id_by_sku('faws-variable'));$variable->set_image_id($id);$variable->save();
$children=$variable->get_children();$variation=wc_get_product($children[0]);$variation->set_image_id($variant_id);$variation->save();
WP_CLI::line(wp_json_encode(array('main'=>$id,'variation_image'=>$variant_id,'source_sha256'=>$original_hash)));
