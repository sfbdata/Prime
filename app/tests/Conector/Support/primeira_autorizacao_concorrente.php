<?php

declare(strict_types=1);

/*
 * O "primeiro navegador" do AutorizacaoConcorrenteTest, num processo e numa conexão só dele: abre a transação, trava a
 * linha da pessoa como o AutorizarConexaoUseCase faz, grava o consentimento ativo, avisa o teste (o id na saída) e só
 * confirma depois de `espera` milissegundos. Nesse intervalo, o teste roda a segunda autorização.
 *
 * Os parâmetros chegam em JSON pela variável CONECTOR_TRAVA (conexão, ids e espera).
 */

$p = json_decode((string) getenv('CONECTOR_TRAVA'), true, 512, \JSON_THROW_ON_ERROR);

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'], $p['dbname']),
    $p['user'],
    $p['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM "user" WHERE id = ? FOR UPDATE')->execute([$p['userId']]);
$gravar = $pdo->prepare('INSERT INTO conector_ia_consentimento (escopos, concedido_em, user_id, tenant_id, cliente_id) VALUES (?, ?, ?, ?, ?) RETURNING id');
$gravar->execute(['["mcp:leitura"]', gmdate('Y-m-d H:i:s'), $p['userId'], $p['tenantId'], $p['clienteId']]);

fwrite(\STDOUT, $gravar->fetchColumn() . "\n");
fflush(\STDOUT);

usleep($p['espera'] * 1000);
$pdo->commit();
