<?php
    use App\Utils\Construct\Template;
    $struct = Template::get();
?>

<!DOCTYPE html>
<html lang="<?= $struct->lang; ?>">

    <head>
        <base href="<?= Template::getBaseDirectory(); ?>">
        <meta charset="<?= $struct->charset; ?>">
    <?php foreach ($struct->seo->meta as $key => $value): ?>
        <meta name="<?= $value->name; ?>" content="<?= $value->content; ?>">
    <?php endforeach; ?>
        <title><?= $struct->seo->title; ?></title>
        <?= Template::getLibs("css", $struct->styles) ?>
        <link rel="stylesheet" type="text/css" href="<?= Template::getStyle($struct->name) ?>" />

    </head>

    <body>
        <?php Template::loadContent(); ?>
 
        <?= Template::getLibs("js", $struct->plugins) ?>
    <script type="module" src="<?= Template::getScript($struct->name) ?>"></script>

    </body>

</html>