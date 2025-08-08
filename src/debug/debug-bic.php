<?php

require_once("../lib/bic.php");

if (isset($_POST['iban'])) {
    $iban = str_replace(" ", "", trim($_POST['iban']));
    $bic = iban2bic($iban);
}

?>
<!DOCTYPE html>
<html>

<head>

    <meta charset="UTF-8">
    <title>IBAN to BIC Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">IBAN to BIC Debug</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($bic)): ?>
                        <div class="alert alert-info mt-3">
                            <strong>BIC:</strong> <?= htmlspecialchars($bic) ?>
                        </div>
                        <hr>
                    <?php endif; ?>
                    <?php if (isset($_GET['show'])): ?>
                        <pre><?php global $BICs; print_r($BICs); ?></pre>
                    <?php endif; ?>
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="iban" class="form-label">IBAN</label>
                            <input type="text" class="form-control" id="iban" name="iban" value="<?= isset($iban) ? htmlspecialchars($iban) : '' ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Get BIC</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</body>

</html>