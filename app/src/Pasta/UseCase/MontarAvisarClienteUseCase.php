<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AvisarClienteOutput;
use App\Pasta\DTO\ChavesDoEnvioAutomatico;
use App\Pasta\DTO\EmailDoClienteOutput;
use App\Pasta\DTO\EnvioAoClienteOutput;
use App\Pasta\DTO\EnvioDaAnaliseOutput;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Twig\NumeroProcessoExtension;

/**
 * Monta o cartão "Avisar cliente" e o que o modal "Enviar ao cliente" e o cartão da Análise IA precisam
 * (spec push-avisar-cliente §2 e §3): os e-mails do cliente da pasta (com o item virtual do cadastro e
 * a marca de descadastrado), a Controladoria do escritório, o histórico de envios da pasta, os envios
 * de cada análise e as chaves do "Envio automático" do cliente (P4d). Leitura pura: o item virtual não
 * é gravado aqui.
 *
 * O histórico é DA PASTA (não do cliente): quem vê esta pasta não fica sabendo o que se enviou nas
 * outras pastas do mesmo cliente.
 */
final class MontarAvisarClienteUseCase
{
    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly EnvioAoClienteRepository $envios,
        private readonly ControladoriaDoEscritorioInterface $controladoria,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly EnvioAutomaticoDoClienteRepository $automaticos,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): AvisarClienteOutput
    {
        $caderno = $this->emails->caderno($pasta, $tenant);
        $cliente = $this->emails->cliente($pasta, $tenant);

        return new AvisarClienteOutput(
            temCliente: $caderno !== null,
            nomeDoCliente: $cliente?->getNomeExibicao(),
            numeroDoProcesso: self::numeroDoProcesso($pasta),
            emails: array_map(fn (ClienteEmail $e): EmailDoClienteOutput => EmailDoClienteOutput::de($e, $this->descadastrado($tenant, $e->getEmail())), $caderno?->itens() ?? []),
            controladoria: array_map(static fn (User $u): array => ['nome' => (string) $u->getFullName(), 'email' => (string) $u->getEmail()], $this->controladoria->pessoas($tenant)),
            historico: array_map(static fn (EnvioAoCliente $e): EnvioAoClienteOutput => EnvioAoClienteOutput::de($e), $this->envios->recentesDaPasta($tenant, $pasta, AvisarClienteOutput::HISTORICO_VISIVEL)),
            totalHistorico: $this->envios->contarDaPasta($tenant, $pasta),
            enviosPorAnalise: $this->enviosPorAnalise($tenant, $pasta),
            automatico: ChavesDoEnvioAutomatico::da($cliente !== null ? $this->automaticos->doClienteDoTenant($cliente, $tenant) : null),
        );
    }

    /** O número do processo principal com a máscara CNJ (o assunto e o corpo do e-mail o usam). */
    public static function numeroDoProcesso(Pasta $pasta): string
    {
        $numero = $pasta->getProcessoPrincipal()?->getNumeroProcesso();

        return $numero !== null ? (new NumeroProcessoExtension())->numeroCnj($numero) : '';
    }

    private function descadastrado(Tenant $tenant, string $email): bool
    {
        return $this->descadastro->estaDescadastrado($tenant, $email, EnvioDeEmailAoClienteInterface::ASSUNTO_DO_DESCADASTRO);
    }

    /** @return array<int, EnvioDaAnaliseOutput> */
    private function enviosPorAnalise(Tenant $tenant, Pasta $pasta): array
    {
        $porAnalise = [];
        foreach ($this->envios->enviosDeAnalisesDaPasta($tenant, $pasta) as $envio) {
            if ($envio->getReferencia() !== null) {
                $porAnalise[$envio->getReferencia()][] = $envio;
            }
        }

        return array_map(static fn (array $envios): EnvioDaAnaliseOutput => EnvioDaAnaliseOutput::de($envios), $porAnalise);
    }
}
