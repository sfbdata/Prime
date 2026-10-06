<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Despacha `ProcessarAnaliseDeInteligencia` para uma análise já gravada como `pendente` — e, se a
 * fila falhar, registra a falha honestamente em vez de derrubar a ação do usuário (padrão do Sync).
 * Compartilhado pelo "Resumir com IA" do Push e pelos agentes da pasta: um só lugar para a regra.
 *
 * Com o EntityManager ainda aberto, a linha vira `falhou` e o chamador devolve a análise (não um
 * 500); com o EM fechado (o transport `doctrine` divide a conexão e uma exceção do banco o fecha)
 * não há como registrar nada — `FilaIndisponivelException` → 503 honesto.
 */
final class EnfileiradorDeAnalise
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws FilaIndisponivelException
     */
    public function enfileirar(AnaliseDeInteligencia $analise, Tenant $tenant): void
    {
        try {
            $this->bus->dispatch(new ProcessarAnaliseDeInteligencia((int) $analise->getId(), (int) $tenant->getId()));
        } catch (\Throwable $e) {
            $this->registrarFalha($analise, $tenant, $e);
        }
    }

    /**
     * @throws FilaIndisponivelException
     */
    private function registrarFalha(AnaliseDeInteligencia $analise, Tenant $tenant, \Throwable $erro): void
    {
        $contexto = [
            'analise' => $analise->getId(),
            'tenant' => $tenant->getId(),
            'tipo' => $analise->getTipo()->value,
            'classe' => $erro::class,
        ];

        if (!$this->analises->emAberto()) {
            $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant} ({classe}); EntityManager fechado, falha não registrada.', $contexto);

            throw new FilaIndisponivelException($analise->getId(), $erro);
        }

        try {
            $analise->falhar('falha ao enfileirar: ' . $erro::class);
            $this->analises->salvar($analise, true);
        } catch (\Throwable $segundoErro) {
            $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant} ({classe}) e ao registrar a falha ({classe2}).', $contexto + ['classe2' => $segundoErro::class]);

            throw new FilaIndisponivelException($analise->getId(), $segundoErro);
        }

        $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant}: {classe}.', $contexto);
    }
}
