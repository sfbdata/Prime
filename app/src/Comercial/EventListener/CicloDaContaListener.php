<?php

declare(strict_types=1);

namespace App\Comercial\EventListener;

use App\Auth\Event\CadastroConfirmado;
use App\Auth\Event\ConfirmacaoDeCadastroEnviada;
use App\Auth\Event\ConviteDeEscritorioAceito;
use App\Auth\Event\ConvitePlataformaAceito;
use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\CicloDaContaRepository;
use App\Comercial\Service\DisparadorDeAutomacao;
use App\Comercial\Service\RegistroDeEnvioExterno;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * As automações do ciclo da conta que reagem a eventos de Auth (spec comercial-e-emails.md §3.5): `val` (cadastro
 * confirmado), `conv` (qualquer convite aceito) e o registro do `cad`. Nunca derruba o fluxo de Auth: toda exceção é
 * capturada e vai ao log só com ids e a chave (I3). Uma boas-vindas por pessoa e escritório (o ciclo deduplica).
 */
final class CicloDaContaListener
{
    public function __construct(
        private readonly CicloDaContaRepository $ciclos,
        private readonly DisparadorDeAutomacao $disparador,
        private readonly RegistroDeEnvioExterno $registro,
        private readonly UserRepository $usuarios,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly Connection $conexao,
    ) {
    }

    #[AsEventListener]
    public function aoConfirmarCadastro(CadastroConfirmado $evento): void
    {
        $this->protegido(Automacao::Val, $evento->userId, function () use ($evento): void {
            $this->ciclos->garantir($evento->userId, $evento->tenantId, $this->clock->now());
            $this->boasVindas(Automacao::Val, $evento->userId, $evento->tenantId);
        });
    }

    #[AsEventListener]
    public function aoAceitarConviteDeEscritorio(ConviteDeEscritorioAceito $evento): void
    {
        $this->protegido(Automacao::Conv, $evento->userId, function () use ($evento): void {
            $this->ciclos->garantir($evento->userId, $evento->tenantId, null);
            $this->boasVindas(Automacao::Conv, $evento->userId, $evento->tenantId);
        });
    }

    #[AsEventListener]
    public function aoAceitarConviteDePlataforma(ConvitePlataformaAceito $evento): void
    {
        $this->protegido(Automacao::Conv, $evento->userId, function () use ($evento): void {
            $this->ciclos->garantir($evento->userId, null, null);
            $this->boasVindas(Automacao::Conv, $evento->userId, null);
        });
    }

    #[AsEventListener]
    public function aoEnviarConfirmacaoDeCadastro(ConfirmacaoDeCadastroEnviada $evento): void
    {
        $this->protegido(Automacao::Cad, null, function () use ($evento): void {
            $this->registro->registrar($evento->email, $evento->assunto, Automacao::Cad->origem(), EnvioDeEmail::ENVIADO, $this->clock->now());
        });
    }

    /** Só marca e manda com a automação ligada: desligada, não consome a vez (ligar depois não alcança o passado). */
    private function boasVindas(Automacao $automacao, int $userId, ?int $tenantId): void
    {
        if (!$this->disparador->estaLigada($automacao) || !$this->ciclos->marcarBoasVindas($userId, $tenantId, $this->clock->now())) {
            return;
        }
        $user = $this->usuarios->find($userId);
        if ($user === null || $user->getEmail() === null) {
            return;
        }
        try {
            $this->disparador->disparar($automacao, (string) $user->getEmail(), $user->getFullName());
        } catch (\Throwable $erro) {
            $this->ciclos->desmarcarBoasVindas($userId, $tenantId);   // a vez não se perde numa falha de envio
            throw $erro;
        }
    }

    /**
     * Numa transação própria da conexão: aninhada na de quem chamou, vira um savepoint, e uma falha de SQL aqui volta só
     * até ele, sem abortar a transação de Auth (se houver uma). Sem transação de fora, é uma transação curta.
     */
    private function protegido(Automacao $automacao, ?int $userId, callable $passo): void
    {
        try {
            $this->conexao->transactional(static fn () => $passo());
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: automação falhou sem afetar o fluxo de Auth.', [
                'automacao' => $automacao->value,
                'userId' => $userId,
                'erro' => $erro::class,
            ]);
        }
    }
}
