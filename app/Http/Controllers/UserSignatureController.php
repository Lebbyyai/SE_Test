<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserSignatureController extends Controller
{
    public function index(Request $r)
    {
        return response()->json(
            UserSignature::where('user_id', $r->user()->id)->get()
        );
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'image' => 'nullable|image|mimes:png|max:1024',
            'image_base64' => 'nullable|string|max:1400000',
            'label' => 'nullable|string|max:255',
        ]);
        if ($r->hasFile('image')) {
            $bytes = $r->file('image')->getContent();
        } elseif (! empty($data['image_base64']) && preg_match('/^data:image\/png;base64,/', $data['image_base64'])) {
            $bytes = base64_decode(substr($data['image_base64'], strpos($data['image_base64'], ',') + 1), true);
        } else {
            throw ValidationException::withMessages(['image' => 'Choose a PNG signature or draw a signature.']);
        }
        $image = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if ($image === false || $image[2] !== IMAGETYPE_PNG || strlen($bytes) > 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'The signature must be a valid PNG up to 1 MB.']);
        }
        $path = 'signatures/'.$r->user()->id.'/'.Str::uuid().'.png';
        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new \RuntimeException('Unable to save the signature file.');
        }
        $oldPaths = [];
        try {
            $sig = DB::transaction(function () use ($r, $data, $path, $image, &$oldPaths) {
                User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
                $old = UserSignature::where('user_id', $r->user()->id)->get();
                $oldPaths = $old->pluck('image_path')->all();
                $sig = UserSignature::create([
                    'user_id' => $r->user()->id, 'image_path' => $path,
                    'label' => trim($data['label'] ?? '') ?: 'Signature',
                    'natural_width' => $image[0], 'natural_height' => $image[1], 'active' => true,
                ]);
                foreach ($old as $previous) {
                    $previous->delete();
                }

                return $sig;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
        Storage::disk('public')->delete($oldPaths);

        return response()->json($sig);
    }

    public function activate(Request $r, UserSignature $signature)
    {
        abort_unless($signature->user_id === $r->user()->id, 403);
        UserSignature::where('user_id', $r->user()->id)->update(['active' => false]);
        $signature->active = true;
        $signature->save();

        return response()->json(['ok' => true]);
    }
}
