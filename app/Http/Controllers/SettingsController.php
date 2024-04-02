<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;

use App\User;
use App\ActivityLog;
use File;
use Hash;
use DB;
use Crypt;
use \Google2FA;

class SettingsController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
    }
    
    public function index()
    {
        $users = User::orderBy('name')->get();
        return view('settings.usermanagement.index')->with('users', $users);
    }
    
    public function addUser(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|max:255|unique:users',
            'email' => 'required|max:255|email',
            'password' => 'required|min:6'
        ]);
        $newUser = new User();
        $newUser->name = $request->input('name');
        $newUser->email = $request->input('email');
        $newUser->phone_number = $request->input('phone_number');
        $newUser->password = Hash::make($request->input('password'));
        $newUser->save();
        ActivityLog::log("Added a new user named \"".$newUser->name."\"", "Settings");
        return back()->with('success', 'User created successfully');
    }
    
    public function deleteUser(Request $request)
    {
        $this->validate($request, [
            'user' => 'required|integer'
        ]);
        $user = User::findOrFail($request->input('user'));
        if ($user->id == auth()->user()->id)
            return back()->withErrors('You cannot delete yourself!');
        ActivityLog::log("Deleted a user named \"".$user->name."\"", "Settings");
        $user->delete();
        return back()->with('success', 'User has been successfully deleted');
    }
    
    public function get_editprofile(Request $request, $id="")
    {
        $user = auth()->user();
        if ($id != "")
            $user = User::findOrFail($id);
        
        $imageDataUri = '';
        $fa_secret = '';
        if ($id == '' && $user->google2fa_secret != null)
        {
            $fa_secret = Crypt::decrypt($user->google2fa_secret);
            $imageDataUri = Google2FA::getQRCodeInline($request->getHttpHost(), $user->email, $fa_secret, 200);
        }
            
        return view('settings.usermanagement.editprofile')->with('user', $user)->with('self', $id)->with('fa_image', $imageDataUri)->with('fa_secret', $fa_secret);
    }
    
    public function post_editprofile(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'password' => 'sometimes|min:6|confirmed',
            'phone_number' => 'min:14|max:14',
            'current_password' => 'sometimes|required',
            'user_id' => 'required|integer',
            ]);
        if ($request->input('user_id') == auth()->user()->id)
        {
            if (!Hash::check($request->input('current_password'), auth()->user()->password))
                return back()->withErrors('Invalid current password');
        }
        $user = User::findOrFail($request->input('user_id'));
        $u = User::where('name', $request->input('name'))->first();
        if ($u != null && $u->id != $request->input('user_id'))
        {
            return back()->withErrors('Username already exists');
        }
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        if (!empty($request->input('password')))
            $user->password = Hash::make($request->input('password'));
        $user->phone_number = $request->input('phone_number');
        $user->save();
        if ($request->input('type') == 'diff')
            return redirect()->action('SettingsController@index')->with('success', 'Profile updated successfully');
        return back()->with('success', 'Profile updated successfully');
    }
    
    public function get_config()
    {
        return view('settings.configs.config');
    }
    
    public function post_config(Request $request)
    {
        $all_updates = $request->except('_token');
        $path = base_path('.env');
        if (file_exists($path) && is_writable($path)) {
            $file_contents = file_get_contents($path);
            $new_uri = config('fiercephish.URI_PREFIX');
            foreach ($all_updates as $key => $value)
            {
                $real_old_value = config('fiercephish.'.$key);
                if ($real_old_value === true)
                    $real_old_value = 'true';
                elseif ($real_old_value === false)
                    $real_old_value = 'false';
                elseif ($real_old_value === null)
                    $real_old_value = 'null';
                
                $real_new_value = $value;
                if ($real_new_value === "" || $real_new_value === null)
                    $real_new_value = 'null';
                if ($key == 'URI_PREFIX')
                {
                    if (!preg_match('/^[a-zA-Z0-9\/]*$/', $value))
                        return back()->withErrors('Settings could not be saved. "Prefix of FiercePhish" must be alphanumeric and can only contain slashes (example: "hidden/link")');
                    $value = trim($value, '/');
                    $real_new_value = trim($real_new_value, '/');
                    $new_uri = $value;
                }
                if ($key == 'IMAP_HOST')
                {
                    \Cache::forget('fp:checkmail_error');
                }
                $file_contents = str_replace($key.'='.$real_old_value, $key.'='.$real_new_value, $file_contents);
            }
            file_put_contents($path, $file_contents);
            $new_redir = '/'.$new_uri.str_replace(config('fiercephish.URI_PREFIX'), '',action('SettingsController@get_config', [], false));
            ActivityLog::log("Application configuration has been edited", "Settings");
            \Artisan::call('config:cache');
            \Artisan::call('queue:restart');
            
            sleep(5); // I know this is terrible, but we have to wait for the config to cache properly...
            while (strstr($new_redir, '//') !== false)
                $new_redir = str_replace('//','/', $new_redir);
            $base = $request->root();
            return redirect($base.$new_redir)->with('success', 'Settings successfully saved!');
        }
        else
        {
            return back()->withErrors('Settings could not be saved. Check the file permissions on "'.$path.'"!');
        }
    }

    public function get_import_export()
    {
        return view('settings.configs.import_export');
    }
    public function post_export_data()
    {
        if (config('fiercephish.DB_CONNECTION') != 'mysql')
        {
            return back()->withErrors('Data export is only supported for mysql databases right now. If you would like another to be supported, make an "Issue" on GitHub');
        }
        ActivityLog::log('FiercePhish Settings exported', 'Settings');
        $storage_class = new \stdClass();
        $sql_dump = [];
        exec("mysqldump -h " .config('fiercephish.DB_HOST')." -P ".config('fiercephish.DB_PORT')." -u ".config('fiercephish.DB_USERNAME')." -p".config("fiercephish.DB_PASSWORD")." ".config('fiercephish.DB_DATABASE'), $sql_dump);
        $storage_class->version = \App\Libraries\CacheHelper::getCurrentVersion();
        $storage_class->sql_dump = implode("\n", $sql_dump);
        $storage_class->env = file_get_contents(base_path('.env'));
        return response(serialize($storage_class))->header('Content-Type', 'application/octet-stream')->header('Content-Disposition','attachment; filename="fiercephish_backup_'.date('Ymd_Gi').'.dat"');
    }
    
    public function post_export_emaillog()
    {
        $data = DB::select(DB::raw('SELECT target_users.`email` as "Email", target_users.`first_name` as "First Name", target_users.`last_name` as "Last Name", target_users.`notes` as "User Notes", emails.`uuid` as "UUID", emails.`sent_time` as "Sent Time", emails.`campaign_id` as "Campaign ID", campaigns.`name` as "Campaign Name", campaigns.`from_name` as "Campaign From Name", campaigns.`from_email` as "Campaign From Email", campaigns.`description` as "Campaign Description" FROM target_users , emails , campaigns WHERE target_users.`id` = emails.`target_user_id` and emails.`campaign_id` = campaigns.`id` order by campaign_id, sent_time;'));
        $fileName = "FiercePhish_Email_Export.csv";
        $headers = array(
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        );
        $columns = ['Email', 'First Name', 'Last Name', 'User Notes', 'UUID', 'Sent Time', 'Campaign ID', 'Campaign Name', 'Campaign From Name', 'Campaign From Email', 'Campaign Description'];
        $callback = function() use($data, $columns) {
            
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            foreach ($data as $row)
            {
                $arr = [];
                foreach ($columns as $col)
                {
                    $arr[] = $row->$col;
                }
                fputcsv($file, $arr);
            }
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
    public function post_import_data(Request $request)
    {
        $this->validate($request, [
            'attachment' => 'required|file',
        ]);
        if (config('fiercephish.DB_CONNECTION') != 'mysql')
        {
            return back()->withErrors('Data import is only supported for mysql databases right now. If you would like another to be supported, make an "Issue" on GitHub');
        }
        $content = File::get($request->file('attachment')->getRealPath());
        $storage_class = false;
        try
        {
            $storage_class = @unserialize($content);
            if ($storage_class === false)
                return back()->withErrors('Data import failed!  This is not a proper FiercePhish backup file!');
        }
        catch (Exception $e)
        {
            return back()->withErrors('Data import failed!  This is not a proper FiercePhish backup file!');
        }
        
        $imported_version = explode('.', $storage_class->version);
        $app_version = explode('.', \App\Libraries\CacheHelper::getCurrentVersion());
        if ($imported_version[0] != $app_version[0] || $imported_version[1] != $app_version[1])
        {
            return back()->withErrors("Data import failed!  This is a data export of FiercePhish version " . $storage_class->version ." and you are running version " . \App\Libraries\CacheHelper::getCurrentVersion());
        }
        \Artisan::call('migrate:reset');
        \Artisan::call('migrate');
        $temp_file = '/tmp/fiercephish_import_'.rand().'.dat';
        file_put_contents($temp_file, $storage_class->sql_dump);
        exec("mysql -h " .config('fiercephish.DB_HOST')." -P ".config('fiercephish.DB_PORT')." -u ".config('fiercephish.DB_USERNAME')." -p".config("fiercephish.DB_PASSWORD")." ".config('fiercephish.DB_DATABASE'). ' < '.$temp_file);
        unlink($temp_file);
        $replace_new_with_old = ['APP_KEY', 'APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'IMAP_HOST', 'IMAP_PORT', 'IMAP_USERNAME', 'IMAP_PASSWORD'];
        $new_env = $storage_class->env;
        foreach ($replace_new_with_old as $tag)
            $new_env = preg_replace('/'.$tag.'=.*$/m', $tag.'='.config('fiercephish.'.$tag), $new_env);
        $new_uri = '';
        preg_match('/URI_PREFIX=(.*)\s*$/m', $new_env, $matches);
        if (count($matches) == 2 && $matches[1] != '' && $matches[1] != 'null')
            $new_uri = trim($matches[1]);
        if ($new_uri == 'null')
            $new_uri = '';
        $new_redir = '/'.$new_uri.str_replace(config('fiercephish.URI_PREFIX'), '',action('SettingsController@get_import_export', [], false));
        while (strstr($new_redir, '//') !== false)
            $new_redir = str_replace('//','/', $new_redir);
        file_put_contents(base_path('.env'), $storage_class->env);
        \Artisan::call('config:cache');
        \Artisan::call('queue:restart');
        sleep(5); // I know this is terrible, but we have to wait for the config to cache properly...
        ActivityLog::log('Imported settings from a previous FiercePhish install', 'Settings');
        $base = $request->root();
        return redirect($base.$new_redir)->with('success', 'Successfully imported settings');
    }
}
