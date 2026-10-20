<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use Psr\Clock\ClockInterface;

/**
 * O caminho comum dos três canais (spec `indicar-bluejus.md`, comportamentos 2, 3 e 10): numa transação com a trava
 * da pessoa, confere o cadastro e as travas (com a contagem já protegida contra corrida), grava a indicação e, se a
 * contagem de 24h dela CRUZOU o alerta (antes < alerta <= depois), grava o alerta e avisa os Masters, uma vez.
 */
final class RegistroDeIndicacao
{
    public const COMPLETE_O_CADASTRO = 'Complete seu cadastro para enviar convites';

    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly IndicacaoConfigRepository $configs,
        private readonly IndicacaoAlertaRepository $alertas,
        private readonly PortaoDaIndicacao $portao,
        private readonly AvisoDeDisparoAlto $aviso,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * A recusa (cadastro, trava, e-mail) sai da transação como valor e só então é lançada: exceção dentro do
     * `wrapInTransaction` fecha o EntityManager, e recusa é fluxo normal, não falha. Toda recusa vem antes de qualquer
     * gravação, então a transação que ela encerra está vazia.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function comTrava(User $user, \Closure $trabalho): mixed
    {
        $resultado = $this->indicacoes->emTransacaoTravada((int) $user->getId(), function () use ($user, $trabalho): mixed {
            try {
                $this->exigirQuePodeIndicar($user);

                return $trabalho();
            } catch (IndicacaoRecusada $recusa) {
                return $recusa;
            }
        });
        if ($resultado instanceof IndicacaoRecusada) {
            throw $resultado;
        }

        return $resultado;
    }

    /** Só dentro de {@see comTrava()}: a contagem de antes vale porque a trava segura os outros envios da pessoa. */
    public function gravar(Indicacao $indicacao, User $user, ?Tenant $escritorio): void
    {
        $agora = $this->relogio->now();
        $antes = $this->indicacoes->contarDoUsuarioDesde((int) $user->getId(), $agora->modify('-24 hours'));
        $this->indicacoes->salvar($indicacao);
        $alerta = $this->configs->obter()->getAlerta24h();
        if ($antes < $alerta && $alerta <= $antes + 1) {
            $this->alertar($user, $escritorio, $antes + 1, $agora);
        }
    }

    public static function nomeDe(User $user): string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $user->getFullName()));

        return $nome !== '' ? $nome : (string) $user->getEmail();
    }

    private function exigirQuePodeIndicar(User $user): void
    {
        if ($this->portao->pendenciasDoCadastro($user) !== []) {
            throw new IndicacaoRecusada(self::COMPLETE_O_CADASTRO);
        }
        $trava = $this->portao->motivoDaTrava($user);
        if ($trava !== null) {
            throw new IndicacaoRecusada($trava);
        }
    }

    /** Textos do desenho: o do card (bj-contas.js L126, `bj-alertas-master`) e o do sino (L126, `BJCentral.notificar`). */
    private function alertar(User $user, ?Tenant $escritorio, int $em24h, \DateTimeImmutable $agora): void
    {
        $nome = self::nomeDe($user);
        $this->alertas->salvar(new IndicacaoAlerta($user, sprintf('%s enviou %d convites em 24h', $nome, $em24h), $agora));
        $deOnde = $escritorio !== null ? sprintf(' (%s)', $escritorio->getName()) : '';
        $this->aviso->avisar(sprintf(
            '%s%s enviou %d convites nas últimas 24h. Revise em Painel do Master › Comercial › Indicações.',
            $nome,
            $deOnde,
            $em24h,
        ));
    }
}
