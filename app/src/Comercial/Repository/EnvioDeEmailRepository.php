<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Envio\MensagemDeEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Persistência dos envios (spec `comercial-e-emails.md` §3.1 e §3.2), por SQL na conexão DBAL.
 *
 * Por que não pelo ORM: o Carteiro é chamado no meio do trabalho de um consumidor, e um `flush` gravaria as entidades
 * pendentes dele (M-f); a reivindicação precisa ser um UPDATE atômico; e a vazão é uma contagem. O escopo (`tenant_id`
 * null = plataforma) é coluna comum: as tabelas são do Master e cruzam escritórios, nenhuma consulta aqui lista por
 * escritório. Toda operação por id ou token mexe só na própria linha.
 *
 * @phpstan-type EnvioParaMandar array{
 *     id: int, token: string, tenant_id: ?int, destinatario: string, nome: ?string, assunto: string, tipo: string,
 *     lista: ?string, origem: string, situacao: string, tem_conteudo: bool, corpo_html: ?string, selo: ?string,
 *     titulo: ?string, reply_to: ?string, cc: list<string>, rastrear: bool, nome_do_remetente: ?string
 * }
 *
 * @extends ServiceEntityRepository<EnvioDeEmail>
 */
class EnvioDeEmailRepository extends ServiceEntityRepository
{
    private const TAMANHO_DO_ERRO = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioDeEmail::class);
    }

    /**
     * Roda o trabalho numa transação só da conexão (sem `flush` do EntityManager). Aninhada numa transação de fora,
     * vira savepoint: a falha desfaz só o trabalho.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->conexao()->transactional(static fn (): mixed => $trabalho());
    }

    /** Grava o registro do envio (sem o corpo) e devolve o id. */
    public function inserir(MensagemDeEmail $m, string $token, string $situacao, \DateTimeImmutable $agora, ?\DateTimeImmutable $proximaTentativaEm): int
    {
        return (int) $this->conexao()->fetchOne(
            'INSERT INTO comercial_envio (token, tenant_id, destinatario, nome, assunto, tipo, lista, origem, situacao,
                 criado_em, proxima_tentativa_em, aberturas, cliques)
             VALUES (:token, :tenant, :para, :nome, :assunto, :tipo, :lista, :origem, :situacao, :agora, :proxima, 0, 0)
             RETURNING id',
            [
                'token' => $token, 'tenant' => $m->escopo?->getId(), 'para' => $m->para, 'nome' => self::corte($m->nome, 180),
                'assunto' => self::corte($m->assunto, 255), 'tipo' => $m->tipo->value, 'lista' => $m->lista,
                'origem' => $m->origem, 'situacao' => $situacao, 'agora' => self::data($agora),
                'proxima' => $proximaTentativaEm === null ? null : self::data($proximaTentativaEm),
            ],
        );
    }

    /** Grava o corpo e os cabeçalhos do envio; some no estado final ({@see self::apagarConteudo()}). */
    public function inserirConteudo(int $envioId, MensagemDeEmail $m): void
    {
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_envio_conteudo (envio_id, corpo_html, selo, titulo, reply_to, cc, rastrear, nome_do_remetente)
             VALUES (:id, :corpo, :selo, :titulo, :reply, :cc, :rastrear, :remetente)',
            [
                'id' => $envioId, 'corpo' => $m->corpoHtml, 'selo' => self::corte($m->selo, 120), 'titulo' => self::corte($m->titulo, 255),
                'reply' => $m->replyTo, 'cc' => json_encode(array_values($m->cc), JSON_THROW_ON_ERROR),
                'rastrear' => $m->rastrear ? 'true' : 'false', 'remetente' => self::corte($m->nomeDoRemetente, 120),
            ],
        );
    }

    /** Reivindicação atômica: só um worker passa do `enfileirado` ao `enviando`. Zero linhas = outro pegou ou já saiu. */
    public function reivindicar(int $id, \DateTimeImmutable $agora): bool
    {
        return $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'enviando', tentativa_em = :agora WHERE id = :id AND situacao = 'enfileirado'",
            ['id' => $id, 'agora' => self::data($agora)],
        ) === 1;
    }

    public function marcarEnviado(int $id, \DateTimeImmutable $agora): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'enviado', enviado_em = :agora, proxima_tentativa_em = NULL WHERE id = :id AND situacao = 'enviando'",
            ['id' => $id, 'agora' => self::data($agora)],
        );
    }

    /** Volta a `enfileirado` (vazão sem vaga ou exceção), com o horário em que a mensagem volta a estar disponível. */
    public function devolverAFila(int $id, \DateTimeImmutable $proximaTentativaEm): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'enfileirado', proxima_tentativa_em = :proxima WHERE id = :id AND situacao = 'enviando'",
            ['id' => $id, 'proxima' => self::data($proximaTentativaEm)],
        );
    }

    /** Falha definitiva (sem mais retry). O erro chega já sanitizado; aqui só se corta no tamanho da coluna. */
    public function marcarFalhou(int $id, string $erro): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'falhou', erro = :erro, proxima_tentativa_em = NULL
             WHERE id = :id AND situacao IN ('enfileirado', 'enviando')",
            ['id' => $id, 'erro' => mb_substr($erro, 0, self::TAMANHO_DO_ERRO)],
        );
    }

    /** O endereço saiu da lista entre o enfileirar e o enviar. */
    public function marcarBloqueado(int $id): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'bloqueado_descadastro', proxima_tentativa_em = NULL
             WHERE id = :id AND situacao IN ('enfileirado', 'enviando')",
            ['id' => $id],
        );
    }

    /** O corpo pode levar credencial (o link do `lemb`): não sobra depois do estado final (N1). */
    public function apagarConteudo(int $id): void
    {
        $this->conexao()->executeStatement('DELETE FROM comercial_envio_conteudo WHERE envio_id = :id', ['id' => $id]);
    }

    /** A vazão (§3.6): quantos saíram de fato desde o instante dado (inclusive). */
    public function contarEnviadosDesde(\DateTimeImmutable $desde): int
    {
        return (int) $this->conexao()->fetchOne(
            "SELECT COUNT(*) FROM comercial_envio WHERE situacao = 'enviado' AND enviado_em >= :desde",
            ['desde' => self::data($desde)],
        );
    }

    /**
     * O envio com o conteúdo, para o handler montar o e-mail. `tem_conteudo` falso = o conteúdo já foi apagado.
     *
     * @return EnvioParaMandar|null
     */
    public function lerParaEnvio(int $id): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT e.id, e.token, e.tenant_id, e.destinatario, e.nome, e.assunto, e.tipo, e.lista, e.origem, e.situacao,
                    c.envio_id IS NOT NULL AS tem_conteudo, c.corpo_html, c.selo, c.titulo, c.reply_to, c.cc, c.rastrear,
                    c.nome_do_remetente
             FROM comercial_envio e LEFT JOIN comercial_envio_conteudo c ON c.envio_id = e.id
             WHERE e.id = :id',
            ['id' => $id],
        );

        return $linha === false ? null : self::paraEnvio($linha);
    }

    /** Abertura pelo pixel (§3.8): soma e guarda a primeira. Token desconhecido não muda nada. */
    public function registrarAbertura(string $token, \DateTimeImmutable $agora): void
    {
        $this->conexao()->executeStatement(
            'UPDATE comercial_envio SET aberturas = aberturas + 1, primeira_abertura_em = COALESCE(primeira_abertura_em, :agora) WHERE token = :token',
            ['token' => $token, 'agora' => self::data($agora)],
        );
    }

    /** Clique num link rastreado (§3.8): soma e guarda o primeiro. Token desconhecido não muda nada. */
    public function registrarClique(string $token, \DateTimeImmutable $agora): void
    {
        $this->conexao()->executeStatement(
            'UPDATE comercial_envio SET cliques = cliques + 1, primeiro_clique_em = COALESCE(primeiro_clique_em, :agora) WHERE token = :token',
            ['token' => $token, 'agora' => self::data($agora)],
        );
    }

    /**
     * A situação dos envios pedidos; ids desconhecidos ficam de fora.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int, situacao: string, enviado_em: ?string, aberturas: int, cliques: int}>
     */
    public function situacoes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: int|string, situacao: string, enviado_em: ?string, aberturas: int|string, cliques: int|string}> $linhas */
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT id, situacao, enviado_em, aberturas, cliques FROM comercial_envio WHERE id IN (:ids) ORDER BY id',
            ['ids' => array_values(array_map('intval', $ids))],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'], 'situacao' => $l['situacao'], 'enviado_em' => $l['enviado_em'],
            'aberturas' => (int) $l['aberturas'], 'cliques' => (int) $l['cliques'],
        ], $linhas);
    }

    /**
     * Para a varredura (M-c): os `enfileirado` cuja mensagem deveria estar disponível antes do instante dado (a
     * mensagem se perdeu, por exemplo em `failed`). O adiado pela vazão tem `proxima_tentativa_em` no futuro e fica fora.
     *
     * @return list<int>
     */
    public function enfileiradosPerdidos(\DateTimeImmutable $antesDe, int $limite): array
    {
        $ids = $this->conexao()->fetchFirstColumn(
            "SELECT id FROM comercial_envio WHERE situacao = 'enfileirado' AND proxima_tentativa_em < :antes ORDER BY id LIMIT " . max(1, $limite),
            ['antes' => self::data($antesDe)],
        );

        return array_values(array_map('intval', $ids));
    }

    /** Para a varredura (M-c): `enviando` parado desde antes do instante dado volta a `enfileirado`, disponível já. */
    public function devolverEnviandoParados(\DateTimeImmutable $antesDe, ?\DateTimeImmutable $agora = null): int
    {
        return (int) $this->conexao()->executeStatement(
            "UPDATE comercial_envio SET situacao = 'enfileirado', proxima_tentativa_em = :agora
             WHERE situacao = 'enviando' AND tentativa_em < :antes",
            ['antes' => self::data($antesDe), 'agora' => self::data($agora ?? new \DateTimeImmutable())],
        );
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return EnvioParaMandar
     */
    private static function paraEnvio(array $l): array
    {
        $cc = \is_string($l['cc']) ? json_decode($l['cc'], true, 4, JSON_THROW_ON_ERROR) : [];

        return [
            'id' => (int) $l['id'], 'token' => (string) $l['token'],
            'tenant_id' => $l['tenant_id'] === null ? null : (int) $l['tenant_id'],
            'destinatario' => (string) $l['destinatario'], 'nome' => self::texto($l['nome']), 'assunto' => (string) $l['assunto'],
            'tipo' => (string) $l['tipo'], 'lista' => self::texto($l['lista']), 'origem' => (string) $l['origem'],
            'situacao' => (string) $l['situacao'], 'tem_conteudo' => (bool) $l['tem_conteudo'],
            'corpo_html' => self::texto($l['corpo_html']), 'selo' => self::texto($l['selo']), 'titulo' => self::texto($l['titulo']),
            'reply_to' => self::texto($l['reply_to']), 'cc' => \is_array($cc) ? array_values(array_map('strval', $cc)) : [],
            'rastrear' => (bool) $l['rastrear'], 'nome_do_remetente' => self::texto($l['nome_do_remetente']),
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    private static function corte(?string $valor, int $tamanho): ?string
    {
        return $valor === null ? null : mb_substr($valor, 0, $tamanho);
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private static function data(\DateTimeImmutable $em): string
    {
        return $em->format('Y-m-d H:i:s');
    }
}
