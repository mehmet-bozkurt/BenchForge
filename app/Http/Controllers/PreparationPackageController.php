<?php

namespace App\Http\Controllers;

use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PreparationPackageController extends Controller
{
    public function download(Request $request, PreparationComparison $preparationComparison, PreparationPackage $preparationPackage): Response
    {
        abort_unless($preparationPackage->status === 'completed', 409, 'Bu paket henüz indirilmeye hazır değil.');
        $section = $request->query('section');

        abort_unless($section === null || (is_string($section) && array_key_exists($section, PreparationPackage::SECTION_LABELS)), 404);

        if ($section !== null) {
            $contents = '# '.PreparationPackage::SECTION_LABELS[$section]."\n\n".$preparationPackage->{$section}."\n";
        } else {
            $contents = '# '.$preparationComparison->title."\n\n"
                .'Model: '.$preparationPackage->model_name.' ('.$preparationPackage->model_id.")\n"
                .'Sözleşme: '.$preparationComparison->contract_version."\n"
                .'Kaynak SHA-256: '.$preparationComparison->source_hash."\n\n"
                ."Bu bir hazırlık paketidir. Gerçek proje geliştirme ve tamamlanma başarısı henüz test edilmedi.\n\n"
                ."## Teknik sınırlar\n\n".$preparationComparison->technologies."\n\n"
                ."## Kaynak şartname (referans metin)\n\n".$preparationComparison->specification."\n\n";

            foreach (PreparationPackage::SECTION_LABELS as $key => $label) {
                $contents .= '## '.$label."\n\n".$preparationPackage->{$key}."\n\n";
            }
        }

        $filename = 'benchforge-hazirlik-'.$preparationComparison->id.'-'.$preparationPackage->id
            .($section === null ? '' : '-'.$section).'.md';

        return response($contents, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
