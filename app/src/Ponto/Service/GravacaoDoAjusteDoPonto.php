<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Grava os ajustes de batida do Ponto (E10, plano-e10-ponto §6): a batida gravada nunca muda, e cada correção entra como
 * uma linha nova de `ponto_ajuste` presa à cabeça da linhagem, com o evento na trilha do Ponto no mesmo COMMIT.
 *
 * ## Quem chama
 *
 * Os UseCases do ajuste (incluir, ajustar, desconsiderar, restaurar) e a análise da justificativa, sempre dentro de
 * `TrilhaDoPonto::emTransacao`: `travar` a pessoa, ler o alvo (`RegistroPontoRepository::alvoDoAjuste`), conferir
 * permissão e a cabeça que a tela abriu, e só então chamar a gravação. Este serviço NÃO confere permissão nem a cabeça
 * esperada; recusa só o que vale para qualquer quem: motivo vazio, horário futuro, alteração que não muda nada e
 * ajuste numa batida que já está fora da conta. Recusado, nada é gravado (nem na trilha).
 *
 * ## Travas
 *
 * `pg_advisory_xact_lock(4210, pessoa)` segura a linhagem das batidas da pessoa até o COMMIT: dois ajustes da mesma
 * pessoa gravam um depois do outro, e cada um lê a cabeça que o anterior deixou. Toda gravação pega a trava de novo
 * (é reentrante na mesma transação) e exige transação ativa. A trava da trilha (4203) vem por último, dentro de
 * `TrilhaDoPonto::registrar`, depois do `flush()`.
 */
final class GravacaoDoAjusteDoPonto
{
    /** Classe de trava (primeiro argumento de pg_advisory_xact_lock): a linhagem das batidas de uma pessoa. */
    public const CLASSE_TRAVA = 4210;

    /** O mesmo limite do CHECK `chk_ponto_ajuste_motivo`. */
    private const MOTIVO_MAXIMO = 2000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrilhaDoPonto $trilha,
        private readonly HorarioFuturoDaBatida $horarioFuturo,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Trava a linhagem das batidas da pessoa até o fim da transação; sem transação ativa, a trava não valeria nada. */
    public function travar(User $pessoa): void
    {
        $conexao = $this->entityManager->getConnection();
        if (!$conexao->isTransactionActive()) {
            throw new \LogicException('A trava da pessoa só vale dentro de uma transação (TrilhaDoPonto::emTransacao).');
        }
        $pessoaId = $pessoa->getId() ?? throw new \LogicException('A trava da pessoa precisa da pessoa já gravada.');

        $conexao->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $pessoaId]);
    }

    /**
     * A batida que não existia. Com a justificativa, é o esquecimento abonado (evento `batida_do_esquecimento`); sem ela,
     * a inclusão do gestor (`batida_incluida`).
     */
    public function incluir(
        Tenant $escritorio,
        User $pessoa,
        string $tipo,
        \DateTimeImmutable $dataHora,
        string $motivo,
        User $autor,
        ?JustificativaPonto $justificativa,
    ): PontoAjuste {
        $motivo = self::motivoValido($motivo);
        $this->exigirNoPassado($dataHora);
        self::exigirDaMesmaFolha($justificativa, $escritorio, $pessoa);
        $this->travar($pessoa);

        $inclusao = PontoAjuste::inclusao($escritorio, $pessoa, $tipo, $dataHora, $motivo, $autor, self::nomeDe($autor), $this->relogio->now(), $justificativa);
        $this->gravar($inclusao);
        $this->registrarNaTrilha(
            $inclusao,
            $justificativa === null ? AcaoDaTrilhaDoPonto::BatidaIncluida : AcaoDaTrilhaDoPonto::BatidaDoEsquecimento,
            $justificativa === null ? DetalheDaTrilhaDoPonto::daAjuste($inclusao) : DetalheDaTrilhaDoPonto::daInclusaoDoEsquecimento($inclusao),
        );

        return $inclusao;
    }

    /** Novo tipo, data ou hora para a batida do alvo (evento `batida_ajustada`). */
    public function alterar(AlvoDoAjuste $alvo, string $tipo, \DateTimeImmutable $dataHora, string $motivo, User $autor): PontoAjuste
    {
        $motivo = self::motivoValido($motivo);
        $this->exigirNoPassado($dataHora);
        self::exigirNaConta($alvo);
        self::exigirQueMude($alvo, $tipo, $dataHora);
        $this->travar($alvo->pessoa);

        $alteracao = PontoAjuste::alteracao($alvo, $tipo, $dataHora, $motivo, $autor, self::nomeDe($autor), $this->relogio->now());
        $this->gravar($alteracao);
        $this->registrarNaTrilha($alteracao, AcaoDaTrilhaDoPonto::BatidaAjustada, DetalheDaTrilhaDoPonto::daAjuste($alteracao));

        return $alteracao;
    }

    /**
     * Tira da conta a batida do alvo. Com `$desfaz` (a inclusão do esquecimento, quando a justificativa volta para
     * análise), o evento é `ajuste_desfeito`; sem ele, `batida_desconsiderada`. A justificativa liga a desconsideração à
     * análise que a fez (a tela não a restaura, decisão A).
     */
    public function desconsiderar(
        AlvoDoAjuste $alvo,
        string $motivo,
        User $autor,
        ?JustificativaPonto $justificativa,
        ?PontoAjuste $desfaz,
    ): PontoAjuste {
        $motivo = self::motivoValido($motivo);
        self::exigirNaConta($alvo);
        self::exigirDaMesmaFolha($justificativa, $alvo->escritorio, $alvo->pessoa);
        $this->travar($alvo->pessoa);

        $desconsideracao = PontoAjuste::desconsideracao($alvo, $motivo, $autor, self::nomeDe($autor), $this->relogio->now(), $justificativa, $desfaz);
        $this->gravar($desconsideracao);
        $acao = $desfaz === null ? AcaoDaTrilhaDoPonto::BatidaDesconsiderada : AcaoDaTrilhaDoPonto::AjusteDesfeito;
        $this->registrarNaTrilha($desconsideracao, $acao, DetalheDaTrilhaDoPonto::daAjuste($desconsideracao));

        return $desconsideracao;
    }

    /**
     * Desfaz a desconsideração (evento `ajuste_desfeito`): a batida volta com o tipo e a data/hora de antes dela. Quem
     * chama garante que a desconsideração ainda é a cabeça; se não for, o índice único de `anterior_id` recusa.
     */
    public function restaurar(PontoAjuste $desconsideracao, string $motivo, User $autor): PontoAjuste
    {
        $motivo = self::motivoValido($motivo);
        $this->travar($desconsideracao->getUser());

        $restauracao = PontoAjuste::restauracao($desconsideracao, $motivo, $autor, self::nomeDe($autor), $this->relogio->now());
        $this->gravar($restauracao);
        $this->registrarNaTrilha($restauracao, AcaoDaTrilhaDoPonto::AjusteDesfeito, DetalheDaTrilhaDoPonto::daAjuste($restauracao));

        return $restauracao;
    }

    /** O motivo sem os espaços das pontas, de 1 a 2000 caracteres (o CHECK do banco mede igual). */
    private static function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);
        if ($tamanho === 0 || $tamanho > self::MOTIVO_MAXIMO) {
            throw AjusteDeBatidaRecusadoException::semMotivo();
        }

        return $motivo;
    }

    private function exigirNoPassado(\DateTimeImmutable $dataHora): void
    {
        if ($this->horarioFuturo->estaNoFuturo($dataHora, $dataHora)) {
            throw AjusteDeBatidaRecusadoException::futuro();
        }
    }

    /** A batida fora da conta (cabeça é uma desconsideração) só volta pela restauração. */
    private static function exigirNaConta(AlvoDoAjuste $alvo): void
    {
        if ($alvo->desconsiderada()) {
            throw AjusteDeBatidaRecusadoException::mudou();
        }
    }

    /** Mesmo tipo e mesma data/hora até o segundo (a coluna não guarda fração): não há o que ajustar. */
    private static function exigirQueMude(AlvoDoAjuste $alvo, string $tipo, \DateTimeImmutable $dataHora): void
    {
        if ($alvo->tipoAtual === $tipo && $alvo->dataHoraAtual?->format('Y-m-d H:i:s') === $dataHora->format('Y-m-d H:i:s')) {
            throw AjusteDeBatidaRecusadoException::nadaMudou();
        }
    }

    /** A justificativa ligada ao ajuste é da mesma pessoa e do mesmo escritório da batida. */
    private static function exigirDaMesmaFolha(?JustificativaPonto $justificativa, Tenant $escritorio, User $pessoa): void
    {
        if ($justificativa === null) {
            return;
        }
        if ($justificativa->getTenant()?->getId() !== $escritorio->getId() || $justificativa->getUser()?->getId() !== $pessoa->getId()) {
            throw new \LogicException('A justificativa do ajuste é de outra pessoa ou de outro escritório.');
        }
    }

    private function gravar(PontoAjuste $ajuste): void
    {
        $this->entityManager->persist($ajuste);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $detalhe */
    private function registrarNaTrilha(PontoAjuste $ajuste, AcaoDaTrilhaDoPonto $acao, array $detalhe): void
    {
        $this->trilha->registrar($ajuste->getTenant(), $ajuste->getAutor(), $acao->value, $detalhe);
    }

    /** O nome que a trilha grava para o autor (a mesma regra de `TrilhaDoPonto`): o nome completo, ou o e-mail. */
    private static function nomeDe(User $autor): string
    {
        $nome = trim((string) $autor->getFullName());

        return $nome !== '' ? $nome : (string) $autor->getEmail();
    }
}
