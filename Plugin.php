<?php namespace Keios\Multisite;

use Cms\Controllers\Themes;
use System\Classes\PluginBase;
use Keios\Multisite\Models\Setting;
use BackendAuth;
use Backend;
use Config;
use Event;
use Cache;
use Request;
use App;
use Flash;
use Backend\Widgets\Form;

/**
 * Multisite Plugin Information File
 * Plugin icon is used with Creative Commons (CC BY 4.0) Licence
 * Icon author: http://pixelkit.com/
 */
class Plugin extends PluginBase
{

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'keios.multisite::lang.details.title',
            'description' => 'keios.multisite::lang.details.description',
            'author'      => 'Keios',
            'icon'        => 'icon-cubes',
        ];
    }

    /**
     * @return array
     */
    public function registerPermissions()
    {
        return [
            'keios.multisite.access_settings' => [
                'tab'   => 'keios.multisite::lang.permissions.tab',
                'label' => 'keios.multisite::lang.permissions.settings',
            ],
        ];
    }

    /**
     * @return array
     */
    public function registerSettings()
    {
        return [
            'multisite' => [
                'label'       => 'keios.multisite::lang.details.title',
                'description' => 'keios.multisite::lang.details.description',
                'category'    => 'system::lang.system.categories.cms',
                'icon'        => 'icon-cubes',
                'url'         => Backend::url('keios/multisite/settings'),
                'permissions' => ['keios.multisite.settings'],
                'order'       => 500,
                'keywords'    => 'multisite domains themes',
            ],
        ];
    }

    /**
     * Multisite boot method
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     * @throws \UnexpectedValueException
     */
    public function boot()
    {
        $backendUri = Config::get('cms.backendUri');
        $requestUrl = Request::url();
        $currentHostUrl = Request::getHost();
        /*
         * Get domain to theme bindings from cache, if it's not there, load them from database,
         * save to cache and use for theme selection.
         */
        $binds = Cache::rememberForever(
            'keios_multisite_settings',
            function () {
                try {
                    $cacheableRecords = Setting::generateCacheableRecords();
                } catch (\Illuminate\Database\QueryException $e) {
                    if (BackendAuth::check()) {
                        Flash::error(trans('keios.multisite:lang.flash.db-error'));
                    }

                    return null;
                }

                return $cacheableRecords;

            }
        );
        // $s = parse_url($binds[464]['domain'])['host'];
        // $t = $currentHostUrl;
        // var_dump($s);
        // echo('<br/>');
        // var_dump($t);
        // dd( stristr($t, $s, TRUE) );

        /*
         * Oooops something went wrong, abort.
         */
        if ($binds === null) {
            return null;
        }
        /*
         * Check if this request is in backend scope and is using domain,
         * that is protected from using backend
         */
        foreach ($binds as $lokasi => $bind) {
            if (preg_match('/\\'.$backendUri.'/', $requestUrl) && preg_match(
                    '/'.$currentHostUrl.'/i',
                    $bind['domain']
                ) && $bind['is_protected']
            ) {
                return App::abort(401, 'Unauthorized.');
            }
        }

        /*
         * If current request is in backend scope, do not check cms themes
         * Allows for current theme changes in October Theme Selector
         */
        if (preg_match('/\\'.$backendUri.'/', $requestUrl)) {
            return null;
        }        
        /*
         * Listen for CMS activeTheme event, change theme according to binds
         * If there's no match, let CMS set active theme
         */
        Event::listen(
            'cms.theme.getActiveTheme',
            function () use ($binds, $currentHostUrl) {
                $theme = null;
                if( App::runningInBackend()  && !App::runningInConsole() )
                {
                    $configs = $binds[BackendAuth::getUser()->lokasi_id];
                    
                    if ($configs) {
                        Config::set('app.url', $configs['domain']);
                        $theme = $configs['theme'];
                    }
                    
                }elseif( !App::runningInBackend() && !App::runningInConsole() ) {
                    $url = str_slug('multhem-'.$currentHostUrl);
                    if( Cache::has($url) )
                    {
                        $bind = Cache::get($url);                        
                    }else{
                        $bind = Cache::rememberForever($url , function() use($binds, $currentHostUrl, $url) {
                            foreach ($binds as $lokasi => $bind) {                       
                                if (stristr($currentHostUrl, parse_url($bind['domain'])['host'], TRUE) === "" ) {
                                    return $bind;
                                }
                            }
                        });
                    }
                    $theme = $bind['theme'];
                    Config::set('app.url', $bind['domain']);
                    
                }        
                return $theme;    
            }
        );

        Event::listen(
            'backend.page.beforeDisplay',
            function (Backend\Classes\Controller $widget) {

                if (!$widget instanceof Themes) {
                    return;
                }
                
                $widget->addViewPath('$/keios/multisite/partials/');
            }
        );
    }

}
