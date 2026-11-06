<?php
// config/dompdf.php

return [
    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Set some default values. It is possible to add all defines that can be set
    | in dompdf_config.inc.php. You can also override the entire config file.
    |
    */
    'show_warnings' => false,   // Throw an Exception on warnings from dompdf

    'public_path' => null,  // Override the public path if needed

    /*
     * Dejavu Sans font is missing glyphs for converted entities, turn it off if you need to show € and £.
     */
    'convert_entities' => true,

    'options' => [
        /**
         * The location of the DOMPDF font directory
         */
        'font_dir' => storage_path('fonts'),

        /**
         * The location of the DOMPDF font cache directory
         */
        'font_cache' => storage_path('fonts'),

        /**
         * The location of a temporary directory.
         */
        'temp_dir' => sys_get_temp_dir(),

        /**
         * ==== IMPORTANT ====
         * chroot: Prevents dompdf from accessing system files.
         */
        'chroot' => realpath(base_path()),

        /**
         * Protocol whitelist
         */
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
        ],

        /**
         * @var string
         */
        'log_output_file' => null,

        /**
         * Whether to enable font subsetting or not.
         *  IMPORTANT: Mettre à true pour réduire la taille du PDF
         */
        'enable_font_subsetting' => true,

        /**
         * The PDF rendering backend to use
         */
        'pdf_backend' => 'CPDF',

        /**
         * html target media view which should be rendered into pdf.
         */
        'default_media_type' => 'screen',

        /**
         * The default paper size.
         *  Pour les factures A4
         */
        'default_paper_size' => 'a4',

        /**
         * The default paper orientation.
         */
        'default_paper_orientation' => 'portrait',

        /**
         * The default font family
         *  Important: Utiliser une police qui supporte les accents français
         */
        'default_font' => 'DejaVu Sans',

        /**
         * Image DPI setting
         */
        'dpi' => 150, //  Augmenté pour une meilleure qualité d'impression

        /**
         * Enable embedded PHP
         * ⚠️ Garder à false pour des raisons de sécurité
         */
        'enable_php' => false,

        /**
         * Enable inline JavaScript
         */
        'enable_javascript' => true,

        /**
         * Enable remote file access
         *  Mettre à true pour charger les images distantes (logo)
         */
        'enable_remote' => true,

        /**
         * List of allowed remote hosts
         */
        'allowed_remote_hosts' => null,

        /**
         * A ratio applied to the fonts height to be more like browsers' line height
         */
        'font_height_ratio' => 1.1,

        /**
         * Use the HTML5 Lib parser
         */
        'enable_html5_parser' => true,
    ],
];