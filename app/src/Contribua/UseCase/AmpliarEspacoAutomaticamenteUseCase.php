<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\AvisoAoPrimario;
use App\Contribua\Service\FolgaDaAmpliacao;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A "Ampliação de GB automática" do desenho 08 VS 03.11.26: "sempre que o uso passar do limite contratado, o espaço e
 * o valor dobram (100 → 200 → 400 GB…)". Regras decididas pelo Coordenador em 04/11/2026 (spec contribua-planos-cora,
 * "Estado da frente").
 *
 * Quem dispara: depois do commit de cada envio (`EspacoPassouDoContratado` quando o envio só entrou pela folga da
 * dobra, `UsoDoEspacoSubiu` com o bloqueio desligado). Fluxo principal: com a chave ligada e uma assinatura Ativa,
 * vigente e ligada no gateway, quando o uso passa do espaço CONTRATADO (plano × multiplicador, sem bônus nem limite do
 * Master), o multiplicador dobra quantas vezes for preciso para caber o uso, até o teto
 * {@see AssinaturaDeEspaco::MULTIPLICADOR_MAXIMO}. O gateway recebe o valor novo primeiro (vale da próxima cobrança,
 * sem proporcional); só depois de aceito o espaço dobra, na hora, e o primário recebe o aviso.
 *
 * Folga: com o bloqueio ligado, o envio que passaria do limite entra se ainda dá para dobrar, até UMA dobra a mais
 * ({@see folgaDaAmpliacao()}). Se o gateway recusar, o escritório fica com no máximo uma dobra sem pagar até o Master
 * resolver; o envio seguinte acima do limite tenta de novo.
 *
 * Alternativos: chave desligada, Free, Aguardando, Inadimplente ou fora da vigência, uso dentro do contratado: nada.
 * No teto: não dobra e avisa o primário uma vez (quando o envio cruza 100%). Gateway recusou ou fora do ar: nada muda,
 * vai ao log e o Master é avisado. Banco falhou depois do gateway aceitar: o valor antigo volta ao gateway e o Master
 * é avisado. Dois envios ao mesmo tempo: a assinatura fica travada (`FOR UPDATE`) e o segundo, ao reler, já encontra
 * o espaço dobrado. A chamada ao gateway acontece com a trava (um aviso do gateway da mesma assinatura espera).
 * Repetição: uma tentativa por escritório em cada requisição (os dois eventos do mesmo envio não chamam o gateway duas
 * vezes) e o mesmo aviso ao Master no máximo uma vez por dia.
 */
final class AmpliarEspacoAutomaticamenteUseCase implements FolgaDaAmpliacao, ResetInterface
{
    public const AMPLIOU = 'ampliou';
    public const NO_TETO = 'no_teto';
    public const FALHOU = 'falhou';
    public const NADA = 'nada';

    /** @var array<int, true> escritórios já tentados nesta requisição */
    private array $tentados = [];

    /** Valor antigo a devolver ao gateway se o banco não gravar depois de ele aceitar; null fora desse intervalo. */
    private ?int $valorParaDesfazer = null;

    public function __construct(
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly GatewaysDeAssinatura $gateways,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly AvisoAoPrimario $avisoAoPrimario,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Quantos bytes a mais o envio pode usar porque a ampliação vai dobrar o espaço: o tamanho de uma dobra, ou zero
     * (chave desligada, sem assinatura ligada ou já no teto).
     */
    public function folgaDaAmpliacao(int $tenantId): int
    {
        if ($this->espacos->doTenant($tenantId)?->isAmpliacaoAutomatica() !== true) {
            return 0;
        }
        $assinatura = $this->assinaturaLigada($tenantId);
        if ($assinatura === null || $assinatura->getMultiplicador() * 2 > AssinaturaDeEspaco::MULTIPLICADOR_MAXIMO) {
            return 0;
        }

        return $assinatura->gigabytes() * RetratoDoEspaco::BYTES_POR_GB;
    }

    /** @param bool $cruzouOLimite o envio levou o uso a 100% agora (o aviso do teto sai uma vez só) */
    public function executar(int $tenantId, int $bytesUsados, bool $cruzouOLimite): string
    {
        if ($this->espacos->doTenant($tenantId)?->isAmpliacaoAutomatica() !== true) {
            return self::NADA;
        }
        $assinatura = $this->assinaturaLigada($tenantId);
        if ($assinatura === null || $bytesUsados <= $assinatura->gigabytes() * RetratoDoEspaco::BYTES_POR_GB || isset($this->tentados[$tenantId])) {
            return self::NADA;
        }
        $this->tentados[$tenantId] = true;

        $this->conexao->beginTransaction();
        try {
            $resultado = $this->ampliarTravado($assinatura, $bytesUsados, $cruzouOLimite);
            $this->conexao->commit();
        } catch (\Throwable $erro) {
            $this->conexao->rollBack();
            if ($this->valorParaDesfazer !== null) {
                $this->desfazerNoGateway($assinatura, $this->valorParaDesfazer, $erro);
            }
            throw $erro;
        } finally {
            $this->valorParaDesfazer = null;
        }

        return $resultado;
    }

    public function reset(): void
    {
        $this->tentados = [];
    }

    private function ampliarTravado(AssinaturaDeEspaco $assinatura, int $bytesUsados, bool $cruzouOLimite): string
    {
        $this->em->refresh($assinatura, LockMode::PESSIMISTIC_WRITE);
        if (!$this->ligada($assinatura) || $bytesUsados <= $assinatura->gigabytes() * RetratoDoEspaco::BYTES_POR_GB) {
            return self::NADA;
        }
        $novo = self::multiplicadorQueCabe($assinatura, $bytesUsados);
        if ($novo === $assinatura->getMultiplicador()) {
            if ($cruzouOLimite) {
                $this->avisarPrimario($assinatura, sprintf('Seu espaço passou de %d GB, o máximo da ampliação automática. Fale com o suporte para ampliar mais.', $assinatura->gigabytes()));
            }

            return self::NO_TETO;
        }
        [$antes, $valorAntes] = [$assinatura->gigabytes(), $assinatura->getValorCentavos()];
        if (!$this->alterarNoGateway($assinatura, $assinatura->valorCom($novo))) {
            return self::FALHOU;
        }
        $this->valorParaDesfazer = $valorAntes;
        $assinatura->ampliarPara($novo);
        $this->planoVigente->atualizar((int) $assinatura->getTenant()->getId(), $this->agora());
        $this->em->flush();
        $this->avisarPrimario($assinatura, sprintf(
            'Seu espaço passou de %d GB: dobramos para %d GB. O valor passa para %s/mês a partir da próxima cobrança.',
            $antes,
            $assinatura->gigabytes(),
            RegistrarApoioUseCase::reais($assinatura->getValorCentavos()),
        ));

        return self::AMPLIOU;
    }

    /** A menor potência de 2 acima da atual em que o uso cabe, parando no teto. */
    private static function multiplicadorQueCabe(AssinaturaDeEspaco $assinatura, int $bytesUsados): int
    {
        $base = $assinatura->getPlano()->getCotaGb() * RetratoDoEspaco::BYTES_POR_GB;
        $multiplicador = $assinatura->getMultiplicador();
        while ($base * $multiplicador < $bytesUsados && $multiplicador * 2 <= AssinaturaDeEspaco::MULTIPLICADOR_MAXIMO) {
            $multiplicador *= 2;
        }

        return $multiplicador;
    }

    /** A assinatura Ativa, vigente e ligada no gateway que dá o maior espaço; null sem nenhuma. */
    private function assinaturaLigada(int $tenantId): ?AssinaturaDeEspaco
    {
        $maior = null;
        foreach ($this->assinaturas->doTenant($tenantId) as $assinatura) {
            if ($this->ligada($assinatura) && ($maior === null || $assinatura->gigabytes() > $maior->gigabytes())) {
                $maior = $assinatura;
            }
        }

        return $maior;
    }

    /** Ativa, ligada no gateway e vigente (com a carência): é a que o `PlanoVigenteDoEscritorio` usa para o espaço. */
    private function ligada(AssinaturaDeEspaco $assinatura): bool
    {
        return $assinatura->getSituacao() === SituacaoDaAssinatura::Ativa
            && $assinatura->getIdDaAssinatura() !== null
            && $assinatura->vigenteEm($this->agora(), $this->configuracao->atual()->getDiasDeCarencia());
    }

    private function alterarNoGateway(AssinaturaDeEspaco $assinatura, int $valorCentavos): bool
    {
        try {
            $gateway = $this->gateways->porCodigo($assinatura->getGateway()) ?? throw new FalhaNoGateway('Gateway da assinatura não está instalado: ' . $assinatura->getGateway());
            $gateway->alterarValor((string) $assinatura->getIdDaAssinatura(), $valorCentavos);

            return true;
        } catch (FalhaNoGateway $falha) {
            $this->logger->error('Contribua: o gateway recusou o valor da ampliação automática', ['assinatura' => $assinatura->getId(), 'gateway' => $assinatura->getGateway(), 'erro' => $falha->getMessage()]);
            $this->avisarMaster($assinatura, 'Ampliação automática não aplicada: o gateway recusou o valor novo (%s, conta Nº %06d). O espaço continua como estava.');

            return false;
        }
    }

    /** O gateway aceitou e o banco não gravou: o valor antigo volta, para não cobrar a dobra que não houve. */
    private function desfazerNoGateway(AssinaturaDeEspaco $assinatura, int $valorAntes, \Throwable $erro): void
    {
        $contexto = ['assinatura' => $assinatura->getId(), 'gateway' => $assinatura->getGateway(), 'valorAntes' => $valorAntes, 'erro' => $erro->getMessage()];
        try {
            $this->gateways->porCodigo($assinatura->getGateway())?->alterarValor((string) $assinatura->getIdDaAssinatura(), $valorAntes);
            $this->logger->error('Contribua: a ampliação automática não gravou; o valor antigo voltou ao gateway', $contexto);
        } catch (\Throwable $falha) {
            $this->logger->critical('Contribua: a ampliação automática não gravou e o valor antigo não voltou ao gateway', $contexto + ['erroAoDesfazer' => $falha->getMessage()]);
        }
        $this->avisarMaster($assinatura, 'Ampliação automática não gravou depois de o gateway aceitar (%s, conta Nº %06d). Confira o valor da assinatura no gateway.');
    }

    /** O mesmo aviso no máximo uma vez por dia: o gateway recusando, cada envio acima do limite tenta de novo. */
    private function avisarMaster(AssinaturaDeEspaco $assinatura, string $modelo): void
    {
        $tenant = $assinatura->getTenant();
        $mensagem = AvisoAoMaster::caber(sprintf($modelo, (string) $tenant->getName(), (int) $tenant->getId()));
        $jaAvisado = $this->conexao->fetchOne(
            'SELECT 1 FROM notificacao WHERE tipo = ? AND titulo = ? AND criada_em > ? LIMIT 1',
            [AvisoAoMaster::TIPO_AMPLIACAO_FALHOU, $mensagem, $this->agora()->modify('-1 day')->format('Y-m-d H:i:s')],
        );
        if ($jaAvisado === false) {
            $this->avisoAoMaster->avisar($tenant, AvisoAoMaster::TIPO_AMPLIACAO_FALHOU, $mensagem, $this->urls->generate('contribua_index') . '#espaco');
        }
    }

    private function avisarPrimario(AssinaturaDeEspaco $assinatura, string $mensagem): void
    {
        $this->avisoAoPrimario->avisar($assinatura->getTenant(), $mensagem, $this->urls->generate('contribua_index') . '#espaco');
    }

    private function agora(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->relogio->now());
    }
}
