<?php
    use App\Utils\Construct\Template;
    $struct = Template::get();
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($struct->lang, ENT_QUOTES, 'UTF-8'); ?>">

    <head>
        <base href="<?= Template::getBaseDirectory(); ?>">
        <meta charset="<?= htmlspecialchars($struct->charset, ENT_QUOTES, 'UTF-8'); ?>">
        <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <?= Template::meta(); ?>
        <?= Template::preloads(); ?>
        <title><?= Template::title(); ?></title>
        <?= Template::styles(); ?>
    </head>

    <body>
        <?php Template::loadContent(); ?>
        <?= Template::moduleData(); ?>
        <?= Template::scripts(); ?>
    </body>

</html>
