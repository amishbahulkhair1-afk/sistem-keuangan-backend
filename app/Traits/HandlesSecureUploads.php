<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait HandlesSecureUploads
{
    /**
     * Ekstensi yang diizinkan untuk bukti transaksi dan nota.
     */
    protected array $allowedPhotoExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * Validasi file unggahan jika request menyertakan file.
     */
    protected function validateSecureFile(Request $request, string $fieldName = 'bukti_foto'): void
    {
        if ($request->hasFile($fieldName)) {
            $request->validate([
                $fieldName => 'file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
            ], [
                "{$fieldName}.file" => "Berkas yang diunggah harus berupa file yang valid.",
                "{$fieldName}.mimes" => "Format file tidak didukung. Harap unggah gambar (JPG, PNG, WEBP) atau PDF.",
                "{$fieldName}.max" => "Ukuran file terlalu besar. Maksimal 5 MB.",
            ]);
        }
    }

    /**
     * Simpan file secara aman dengan nama acak kriptografis di folder public/uploads.
     * Mengembalikan path relatif yang aman disimpan di database (misal: 'uploads/bukti_...').
     */
    protected function uploadSecureFile(?UploadedFile $file, string $prefix = 'bukti'): ?string
    {
        if (!$file || !$file->isValid()) {
            return null;
        }

        $rawExt = strtolower($file->getClientOriginalExtension());
        $ext = in_array($rawExt, $this->allowedPhotoExtensions) ? $rawExt : 'jpg';

        $filename = $prefix . '_' . date('Ymd_His') . '_' . Str::random(16) . '.' . $ext;
        $uploadDir = public_path('uploads');

        if (!File::isDirectory($uploadDir)) {
            File::makeDirectory($uploadDir, 0755, true, true);
        }

        $file->move($uploadDir, $filename);

        return 'uploads/' . $filename;
    }

    /**
     * Hapus file foto dari disk jika ada.
     */
    protected function deleteSecureFile(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $cleanBasename = basename($path);
        // Cegah traversal atau penghapusan direktori
        if (in_array($cleanBasename, ['', '.', '..', '.gitkeep', '.htaccess', 'index.html'])) {
            return;
        }

        $fullPath = public_path('uploads/' . $cleanBasename);
        if (File::exists($fullPath) && !File::isDirectory($fullPath)) {
            File::delete($fullPath);
        }
    }
}
