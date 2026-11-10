<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Service\NotificacaoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A batida fora do raio das sedes registrada como trabalho externo (spec ponto-desenho-0811 §5 E12, P18 e P74), para o
 * PontoController mudar o mínimo.
 *
 * Só com a cerca valendo (sem home office e com a geolocalização ligada), a posição fora ou incerta, a função permitida
 * no escritório e o pedido `trabalhoExterno: true` com o local e o motivo. A batida entra sem sede e passa por todas as
 * regras de sempre; no mesmo COMMIT nasce a justificativa `trabalho_externo` em análise, ligada a ela, que nunca abona
 * saldo: a batida conta enquanto está em análise, e aprovar só a confirma. Os gestores são avisados depois do COMMIT.
 */
final class TrabalhoExternoDaBatida
{
    public const LIMITE_DO_MOTIVO = 500;

    public const MENSAGEM_SEM_MOTIVO = 'Informe o local e o motivo.';
    public const MENSAGEM_MOTIVO_LONGO = 'O local e o motivo podem ter até 500 caracteres.';
    public const MENSAGEM_REGISTRADA = 'Ponto registrado como trabalho externo. Ele segue para a análise do gestor.';

    /** A justificativa ligada à batida é só do gestor: o colaborador não a edita (nem exclui, ver a recusa da exclusão). */
    public const MENSAGEM_EDICAO_RECUSADA = 'A justificativa da batida de trabalho externo não pode ser editada. Ela segue para a análise do gestor.';

    public function __construct(
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** P74: o escritório permite o trabalho externo? Sem a linha de configuração, vale o padrão (permitido). */
    public function permitido(Tenant $tenant): bool
    {
        return $this->configuracoes->doTenant($tenant)?->permiteTrabalhoExterno() ?? ConfiguracaoDoPonto::PERMITE_TRABALHO_EXTERNO_PADRAO;
    }

    /**
     * O pedido quer registrar como trabalho externo? Só o booleano `true` conta.
     *
     * @param array<mixed> $dados o JSON da batida
     */
    public static function foiPedido(array $dados): bool
    {
        return ($dados['trabalhoExterno'] ?? false) === true;
    }

    /**
     * O local e o motivo do pedido, sem os espaços das pontas; o que não é texto vale vazio.
     *
     * @param array<mixed> $dados o JSON da batida
     */
    public static function motivoDo(array $dados): string
    {
        $motivo = $dados['motivoTrabalhoExterno'] ?? null;

        return is_string($motivo) ? trim($motivo) : '';
    }

    /** A recusa do local e motivo (vazio ou longo demais), ou null quando ele serve. */
    public static function recusaDoMotivo(string $motivo): ?string
    {
        if ($motivo === '') {
            return self::MENSAGEM_SEM_MOTIVO;
        }

        return mb_strlen($motivo) > self::LIMITE_DO_MOTIVO ? self::MENSAGEM_MOTIVO_LONGO : null;
    }

    /** Marca a batida como trabalho externo: sem sede e sem o nome dela, com o local e o motivo. */
    public static function marcar(RegistroPonto $batida, string $motivo): void
    {
        $batida->setTrabalhoExterno(true);
        $batida->setMotivoTrabalhoExterno($motivo);
        $batida->setSede(null);
        $batida->setSedeNomeSnapshot(null);
    }

    /**
     * A justificativa `trabalho_externo` em análise da batida, ainda sem gravar: quem chama a persiste no mesmo COMMIT
     * da batida. A data é o dia do momento da batida (na guardada sem internet, a hora do aparelho, E14).
     */
    public function justificativaDa(RegistroPonto $batida): JustificativaPonto
    {
        $user = $batida->getUser();
        $tenant = $batida->getTenant();
        $momento = $batida->getDataHora();
        if ($user === null || $tenant === null || $momento === null || !$batida->isTrabalhoExterno()) {
            throw new \LogicException('A justificativa do trabalho externo precisa da batida marcada, com pessoa, escritório e momento.');
        }

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime($momento->format('Y-m-d')));
        $justificativa->setTipo(TipoJustificativa::TrabalhoExterno->value);
        $justificativa->setStatus('pendente');
        $justificativa->setMotivo($batida->getMotivoTrabalhoExterno());
        $justificativa->setRegistroPonto($batida);
        $justificativa->setBatchId(bin2hex(random_bytes(16)));   // um lote de um dia, como o envio pela tela

        return $justificativa;
    }

    /**
     * Depois do COMMIT da batida: avisa os gestores, como a justificativa enviada pela tela. A batida já entrou, e a
     * falha do aviso só vai para o log.
     */
    public function avisarGestores(JustificativaPonto $justificativa, Tenant $tenant): void
    {
        try {
            $urlGestor = $this->urls->generate('app_tenant_user_edit_role', [
                'tenantId' => $tenant->getId(),
                'id'       => $justificativa->getUser()?->getId(),
                'tab'      => 'justificativas',
            ]);
            $this->notificacoes->notificarJustificativaEnviada($justificativa, $urlGestor, $tenant);
        } catch (\Throwable $falha) {
            $this->logger->error('Ponto E12: a batida de trabalho externo entrou, mas o aviso aos gestores falhou.', [
                'justificativa_id' => $justificativa->getId(),
                'tenant_id'        => $tenant->getId(),
                'erro'             => $falha->getMessage(),
            ]);
        }
    }
}
