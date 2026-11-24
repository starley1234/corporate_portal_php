<?php
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
class HTML {static function chars($value) {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}}
class View {static function factory($name,$data=[]) {return '<header>Profile</header>';}}
function profileRender(array $profile_roles) {
 $user=(object)['username'=>'Test','email'=>'test@example.invalid','last_login'=>0,'email_confirmed'=>TRUE];
 ob_start();include dirname(__DIR__,2).'/application/views/auth/profile.php';return ob_get_clean();
}
$html=profileRender(['login','admin','<custom-role>']);
foreach (['Назначенные роли','login','admin','&lt;custom-role&gt;'] as $text)
 if (strpos($html,$text)===FALSE) throw new RuntimeException('Missing escaped profile role');
if (strpos($html,'<custom-role>')!==FALSE) throw new RuntimeException('Unescaped role');
if (strpos(profileRender([]),'Роли не назначены')===FALSE) throw new RuntimeException('Missing empty roles state');
echo "Profile roles: 6 checks OK\n";
