<?php

namespace App\Http\Controllers\Admins;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\MailConfigHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    public function index()
    {
        return view('admins.pages.settings.index');
    }

    public function apiList()
    {
        try {
            $settings = Setting::latest('id')->get();
            return response()->json([
                'success' => true,
                'data' => array_values($settings->toArray())
            ]);
        } catch (\Throwable $e) {
            \Log::error('Setting apiList error: ' . $e->getMessage());
            return response()->json(['success' => false, 'data' => [], 'error' => $e->getMessage()], 500);
        }
    }

    public function apiSaveAll(Request $request)
    {
        try {
            $data = $request->except(['_token', 'site_logo', 'site_favicon', 'og_image']);

            // Lưu các trường văn bản / URL / số
            foreach ($data as $key => $value) {
                // Giữ nguyên mật khẩu SMTP cũ nếu admin để trống khi cập nhật
                if ($key === 'mail_password' && trim((string) $value) === '') {
                    continue;
                }

                if ($value === null) {
                    $value = '';
                }

                $type = 'string';
                if (in_array($key, ['meta_description', 'meta_keywords', 'copyright', 'address'])) {
                    $type = 'textarea';
                } elseif (in_array($key, ['url', 'facebook', 'facebook_url', 'zalo', 'zalo_url', 'instagram', 'twitter', 'tiktok', 'youtube', 'deals_url'])) {
                    $type = 'url';
                } elseif (in_array($key, ['email', 'mail_from_address', 'mail_receive_address'])) {
                    $type = 'email';
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => is_string($value) ? trim($value) : $value, 'type' => $type]
                );
            }

            // Xử lý upload ảnh (site_logo, site_favicon, og_image)
            $destinationPath = public_path('storage/clients/imgs/settings');
            if (!File::isDirectory($destinationPath)) {
                File::makeDirectory($destinationPath, 0755, true, true);
            }

            $imageFields = [
                'site_logo' => 'logo-haiphongtech',
                'site_favicon' => 'favicon-haiphongtech',
                'og_image' => 'og-image-haiphongtech'
            ];

            foreach ($imageFields as $field => $prefix) {
                if ($request->hasFile($field)) {
                    $request->validate([
                        $field => 'image|mimes:jpeg,png,jpg,gif,webp,svg,ico|max:5120'
                    ]);

                    $file = $request->file($field);
                    $filename = $prefix . '-' . time() . '.' . $file->getClientOriginalExtension();
                    $file->move($destinationPath, $filename);

                    Setting::updateOrCreate(
                        ['key' => $field],
                        ['value' => $filename, 'type' => 'image']
                    );
                }
            }

            // Xóa cache toàn cục
            Cache::forget('global_settings');

            return response()->json([
                'success' => true,
                'message' => 'Lưu toàn bộ cài đặt hệ thống thành công!'
            ]);
        } catch (\Throwable $e) {
            \Log::error('Save all settings error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra: ' . $e->getMessage()
            ], 500);
        }
    }

    public function apiStore(Request $request)
    {
        $request->validate([
            'key' => 'required|string|max:255',
            'type' => 'required|in:string,text,textarea,integer,float,number,boolean,json,email,url,image',
        ]);

        $value = $request->value;

        if ($request->type === 'image') {
            if ($request->hasFile('value')) {
                $file = $request->file('value');
                $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '-' . time() . '.' . $file->getClientOriginalExtension();
                $destinationPath = public_path('storage/clients/imgs/settings');
                if (!File::isDirectory($destinationPath)) {
                    File::makeDirectory($destinationPath, 0755, true, true);
                }
                $file->move($destinationPath, $filename);
                $value = $filename;
            } elseif ($request->id) {
                $setting = Setting::find($request->id);
                $value = $setting ? $setting->value : '';
            } else {
                $request->validate(['value' => 'required|image']);
            }
        } elseif ($request->type === 'json') {
            $request->validate(['value' => 'required|string']);
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['errors' => ['value' => ['Dữ liệu JSON không hợp lệ! Vui lòng kiểm tra lại cú pháp.']]], 422);
            }
        } else {
            $request->validate(['value' => 'nullable']);
            if ($value === null) $value = '';
        }

        $setting = Setting::updateOrCreate(
            ['id' => $request->id],
            [
                'key' => trim($request->key),
                'value' => is_string($value) ? trim($value) : $value,
                'type' => $request->type,
            ]
        );

        Cache::forget('global_settings');

        return response()->json([
            'success' => true,
            'message' => $request->id ? 'Cập nhật cài đặt thành công!' : 'Tạo mới cài đặt thành công!',
            'data' => $setting
        ]);
    }

    public function apiShow($id)
    {
        $setting = Setting::findOrFail($id);
        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function apiDestroy($id)
    {
        $setting = Setting::findOrFail($id);
        
        if ($setting->type === 'image' && $setting->value) {
            $oldPath = public_path('storage/clients/imgs/settings/' . $setting->value);
            if (File::exists($oldPath)) {
                @File::delete($oldPath);
            }
        }

        $setting->delete();
        Cache::forget('global_settings');

        return response()->json([
            'success' => true,
            'message' => 'Xóa cài đặt thành công!'
        ]);
    }

    /**
     * Gửi email kiểm tra cấu hình SMTP & Mail nhận ngay từ Admin Settings.
     */
    public function apiTestMail(Request $request)
    {
        $rows = MailConfigHelper::applyFromSettings();
        $target = trim((string) $request->input('test_email', ''));

        if ($target === '') {
            $receivers = MailConfigHelper::getReceiveEmails($rows);
            $target = $receivers[0] ?? '';
        }

        if ($target === '' || !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập Email nhận hợp lệ trước khi gửi thử.',
            ], 422);
        }

        try {
            $siteName = $rows['name'] ?? 'Hải Phòng Tech';
            Mail::html(
                '<div style="font-family:Inter,Arial,sans-serif;padding:18px;border:1px solid #d8e5f1;border-radius:6px;max-width:520px;">'
                . '<h3 style="margin:0 0 8px;color:#1e73be;">Kiểm tra cấu hình Email thành công!</h3>'
                . '<p style="margin:0 0 6px;color:#334155;font-size:13.5px;">Hệ thống <strong>' . e($siteName) . '</strong> đã gửi thử email thành công bằng cấu hình cài đặt trong Admin.</p>'
                . '<p style="margin:0;color:#64748b;font-size:12px;">Thời gian: ' . now()->format('H:i:s d/m/Y') . '</p>'
                . '</div>',
                function ($msg) use ($target, $siteName) {
                    $msg->to($target)->subject("[{$siteName}] Kiểm tra cấu hình Email thành công");
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'Đã gửi email kiểm tra thành công tới ' . $target . '!',
            ]);
        } catch (\Throwable $e) {
            \Log::error('Test mail failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gửi email thất bại: ' . $e->getMessage(),
            ], 500);
        }
    }
}
