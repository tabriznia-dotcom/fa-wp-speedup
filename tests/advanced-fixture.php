<?php
if(!defined('WP_CLI') || !WP_CLI || home_url()!=='http://localhost:8080'){throw new RuntimeException('Disposable site required');}
$uploads=wp_upload_dir();
$dir=$uploads['basedir'].'/faws-fixture';wp_mkdir_p($dir);
foreach(array('optional','deferred','dependency','middle','consumer','localized','mobile') as $name){
 file_put_contents($dir.'/'.$name.'.js','window.advancedTest=window.advancedTest||{};window.advancedTest.'. $name .'=true;');
}
file_put_contents($dir.'/optional.css',':root{--faws-test:73}');
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR.'/faws-advanced-fixture.php', <<<'CODE'
<?php
add_action('wp_enqueue_scripts',static function(){
 if(home_url()!=='http://localhost:8080'||!get_option('faws_advanced_fixture')||!is_front_page()){return;}
 $base=wp_upload_dir()['baseurl'].'/faws-fixture/';
 wp_enqueue_script('sample-optional',$base.'optional.js',array(),'1',true);
 wp_enqueue_script('sample-deferred',$base.'deferred.js',array(),'1',true);
 wp_enqueue_script('sample-dependency',$base.'dependency.js',array(),'1',true);
 wp_enqueue_script('sample-middle',$base.'middle.js',array('sample-dependency'),'1',true);
 wp_enqueue_script('sample-consumer',$base.'consumer.js',array('sample-middle'),'1',true);
 wp_enqueue_script('sample-localized',$base.'localized.js',array(),'1',true);
 wp_localize_script('sample-localized','advancedLocalData',array('safe'=>'yes'));
 wp_enqueue_script('mobile-menu-example',$base.'mobile.js',array(),'1',true);
 wp_enqueue_style('sample-style',$base.'optional.css',array(),'1');
},5);
CODE
);
update_option('faws_advanced_fixture',true);
WP_CLI::success('Local browser fixture enabled');
