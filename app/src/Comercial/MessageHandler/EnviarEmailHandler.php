<?php

declare(strict_types=1);

namespace App\Comercial\MessageHandler;

use App\Comercial\Envio\LayoutDoEmail;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\MontadorDeEmail;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Envia um e-mail registrado pelo Carteiro (spec `comercial-e-emails.md` §3.1 e §3.6):
 *   1. reivindica (UPDATE atômico; zero linhas = outro worker pegou ou já saiu: para);
 *   2. de campanha encerrada (origem `campanha:<id>`, spec §8.1.5): vira `cancelado`, não sai, e o conteúdo some;
 *      em `lista`, relê o descadastro: descadastrado vira `bloqueado_descadastro` e o conteúdo some;
 *   3. vazão por contagem em `comercial_envio`: sem vaga, volta a `enfileirado` e reenfileira com DelayStamp;
 *   4. monta e manda. Sucesso: `enviado` e o conteúdo some. Exceção: volta a `enfileirado` e relança (retry do
 *      Messenger; a falha definitiva é do {@see \App\Comercial\EventListener\FalhaDeEnvioListener}).
 *
 * Logs só com o id do envio, nunca o endereço.
 *
 * @phpstan-import-type EnvioParaMandar from EnvioDeEmailRepository
 */
#[AsMessageHandler]
final class EnviarEmailHandler
{
    /**
     * Respeitam só o limite por minuto, nunca o teto diário: os transacionais do ciclo da conta (N6, as boas-vindas
     * nunca esperam o dia seguinte por causa de campanha) e o "Enviar teste" da campanha, que vai só ao Master logado
     * (§8.4, com teto próprio de 10 por hora).
     */
    public const ORIGENS_ISENTAS_DO_TETO_DIARIO = [
        'automacao:cad', 'automacao:lemb', 'automacao:val', 'automacao:conv',
        'automacao:e90', 'automacao:e100', 'automacao:ped',
        'teste_campanha',
    ];

    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly TenantRepository $tenants,
        private readonly MontadorDeEmail $montador,
        private readonly MailerInterface $mailer,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
        private readonly int $enviosPorMinuto,
        private readonly int $tetoDiario,
        private readonly CampanhaDeEmailRepository $campanhas,
    ) {
    }

    public function __invoke(EnviarEmail $mensagem): void
    {
        $id = $mensagem->envioId;
        if (!$this->envios->reivindicar($id, $this->relogio->now())) {
            return;
        }
        $envio = $this->envios->lerParaEnvio($id);
        $email = $envio === null ? null : $this->mensagem($envio);
        if ($envio === null || $email === null) {
            $this->envios->marcarFalhou($id, 'Conteúdo do envio ausente ou inválido.');
            $this->envios->apagarConteudo($id);
            $this->logger->error('Envio de e-mail sem conteúdo válido.', ['envioId' => $id]);

            return;
        }
        if ($this->campanhaEncerrada($id, $email->origem) || $this->bloqueadoPeloDescadastro($id, $email) || $this->adiadoPelaVazao($id, $email->origem)) {
            return;
        }
        $this->mandar($id, $email, $envio['token']);
    }

    /** O freio do console e a chave da instalação (spec §8.1.2 e §8.1.5): o que já estava na fila também não sai. */
    private function campanhaEncerrada(int $id, string $origem): bool
    {
        if (preg_match('/^campanha:(\d{1,10})$/', $origem, $achado) !== 1 || !$this->campanhas->estaCancelada((int) $achado[1])) {
            return false;
        }
        $this->envios->marcarCancelado($id);
        $this->envios->apagarConteudo($id);

        return true;
    }

    private function bloqueadoPeloDescadastro(int $id, MensagemDeEmail $email): bool
    {
        if ($email->tipo !== TipoDeEnvio::Lista || $email->lista === null
            || !$this->descadastro->estaDescadastrado($email->escopo, $email->para, $email->lista)) {
            return false;
        }
        $this->envios->marcarBloqueado($id);
        $this->envios->apagarConteudo($id);

        return true;
    }

    private function adiadoPelaVazao(int $id, string $origem): bool
    {
        $agora = $this->relogio->now();
        $disponivelEm = $this->disponivelEm($origem, $agora);
        if ($disponivelEm === null) {
            return false;
        }
        $this->envios->devolverAFila($id, $disponivelEm);
        $espera = max(1, $disponivelEm->getTimestamp() - $agora->getTimestamp()) * 1000;
        $this->bus->dispatch(new EnviarEmail($id), [new DelayStamp($espera)]);

        return true;
    }

    /**
     * Quando há vaga de novo, ou null se há vaga agora. Por minuto: os enviados nos últimos 60 s. Por dia: os enviados
     * desde 00:00 de hoje (fuso do servidor); sem vaga, 00:05 do dia seguinte.
     */
    public function disponivelEm(string $origem, \DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        if ($this->envios->contarEnviadosDesde($agora->modify('-60 seconds')) >= $this->enviosPorMinuto) {
            return $agora->modify('+60 seconds');
        }
        if (\in_array($origem, self::ORIGENS_ISENTAS_DO_TETO_DIARIO, true)) {
            return null;
        }
        $hoje = $agora->setTime(0, 0);
        if ($this->envios->contarEnviadosDesde($hoje) >= $this->tetoDiario) {
            return $hoje->modify('+1 day')->setTime(0, 5);
        }

        return null;
    }

    private function mandar(int $id, MensagemDeEmail $email, string $token): void
    {
        try {
            $this->mailer->send($this->montador->montar($email, $token));
        } catch (\Throwable $erro) {
            $this->envios->devolverAFila($id, $this->relogio->now());
            $this->logger->warning('Envio de e-mail falhou; volta à fila para nova tentativa.', ['envioId' => $id, 'classe' => $erro::class]);

            throw $erro;
        }
        $this->envios->marcarEnviado($id, $this->relogio->now());
        $this->envios->apagarConteudo($id);
    }

    /**
     * Refaz a mensagem a partir do registro (valida de novo). Null se o conteúdo sumiu, o escritório não existe
     * mais ou a mensagem não passa mais na validação (nunca sai como se fosse da plataforma).
     *
     * @param EnvioParaMandar $envio
     */
    private function mensagem(array $envio): ?MensagemDeEmail
    {
        $tipo = TipoDeEnvio::tryFrom($envio['tipo']);
        $escopo = $this->escopo($envio['tenant_id']);
        if (!$envio['tem_conteudo'] || $envio['corpo_html'] === null || $tipo === null || ($envio['tenant_id'] !== null && $escopo === null)) {
            return null;
        }
        try {
            return new MensagemDeEmail(
                $envio['destinatario'],
                $envio['assunto'],
                $envio['corpo_html'],
                $tipo,
                $envio['origem'],
                $envio['lista'],
                $escopo,
                $envio['nome'],
                $envio['selo'],
                $envio['titulo'],
                $envio['reply_to'],
                $envio['cc'],
                $envio['rastrear'],
                $envio['nome_do_remetente'],
                $envio['motivo_do_envio'],
                LayoutDoEmail::tryFrom($envio['layout']) ?? LayoutDoEmail::Padrao,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function escopo(?int $tenantId): ?Tenant
    {
        if ($tenantId === null) {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
