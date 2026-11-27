<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Auth\Service\CadastroMailer;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Enum\Automacao;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Exception\AutomacaoRecusada;
use App\Comercial\Service\DisparadorDeAutomacao;
use App\Comercial\Service\ModelosDeAutomacao;
use App\Comercial\Service\RegistroDeEnvioExterno;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Simular novo cadastro" do desenho 05 (L.263, L.566), que passou a enviar de verdade (B3 do teste do Farlei,
 * 27/11/2026): o Master informa um e-mail (a tela sugere o dele) e recebe ali os dois e-mails que uma conta nova
 * dispara, com os mesmos modelos e o assunto começando por "[TESTE]", como o "Enviar teste" da campanha:
 *   1. a confirmação do e-mail ({@see CadastroMailer::enviarConfirmacaoDeTeste()}), com o botão levando à tela de entrar;
 *   2. as boas-vindas (`val`), montadas pelo {@see ModelosDeAutomacao} e entregues ao Carteiro, só se a automação estiver
 *      ligada (como num cadastro de verdade).
 *
 * Não cria conta, escritório, ciclo nem indicação, e não dispara os eventos de Auth: as origens `simulacao:cad` e
 * `simulacao:val` aparecem em "Últimos disparos" e ficam fora dos números das automações. Teto de 30 simulações por hora
 * (contadas pela linha da confirmação, no banco: sobrevive a deploy), isento do teto diário de envio.
 */
final class SimularNovoCadastroUseCase
{
    public const ORIGEM_CONFIRMACAO = 'simulacao:cad';
    public const ORIGEM_BOAS_VINDAS = 'simulacao:val';
    public const PREFIXO = EnviarTesteDaCampanhaUseCase::PREFIXO;
    public const TETO_POR_HORA = 30;
    public const TETO_ATINGIDO = 'Limite de 30 simulações por hora atingido.';
    public const ENDERECO_INVALIDO = 'Informe um e-mail válido para receber a simulação.';
    public const FALHA_NO_ENVIO = 'Não foi possível enviar o e-mail de confirmação agora. Tente de novo em instantes.';
    public const MENSAGEM = 'Simulação enviada para %s: a confirmação do e-mail e as boas-vindas, com "[TESTE]" no assunto. Nenhuma conta foi criada.';
    public const MENSAGEM_SEM_BOAS_VINDAS = 'Simulação enviada para %s: só a confirmação do e-mail, com "[TESTE]" no assunto, porque as boas-vindas estão desligadas. Nenhuma conta foi criada.';
    public const MENSAGEM_BOAS_VINDAS_FALHOU = 'A confirmação do e-mail foi enviada para %s, com "[TESTE]" no assunto, mas as boas-vindas não entraram na fila. Nenhuma conta foi criada.';
    /** O nome no "Olá" quando o endereço não é o do Master (a mesma palavra do e-mail sem nome). */
    public const NOME_DE_EXEMPLO = 'Colega';

    public function __construct(
        private readonly CadastroMailer $mailer,
        private readonly ModelosDeAutomacao $modelos,
        private readonly DisparadorDeAutomacao $disparador,
        private readonly CarteiroInterface $carteiro,
        private readonly RegistroDeEnvioExterno $registro,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(User $master, string $para): string
    {
        $destino = new DestinatarioDaCampanha($para);
        if (!$destino->valido()) {
            throw new AutomacaoRecusada(self::ENDERECO_INVALIDO);
        }
        $agora = $this->clock->now();
        if ($this->registro->contarDesde(self::ORIGEM_CONFIRMACAO, $agora->modify('-1 hour')) >= self::TETO_POR_HORA) {
            throw new AutomacaoRecusada(self::TETO_ATINGIDO);
        }
        $doMaster = $destino->email === mb_strtolower(trim((string) $master->getEmail()));
        $nome = $doMaster ? $master->getFullName() : null;

        try {
            $assunto = $this->mailer->enviarConfirmacaoDeTeste($destino->email, (string) ($nome ?? self::NOME_DE_EXEMPLO), self::PREFIXO);
        } catch (\RuntimeException $e) {
            throw new AutomacaoRecusada(self::FALHA_NO_ENVIO, 0, $e);
        }
        $this->registro->registrar($destino->email, $assunto, self::ORIGEM_CONFIRMACAO, EnvioDeEmail::ENVIADO, $agora);

        if (!$this->disparador->estaLigada(Automacao::Val)) {
            return sprintf(self::MENSAGEM_SEM_BOAS_VINDAS, $destino->email);
        }
        return sprintf($this->enviarBoasVindas($destino->email, $nome) ? self::MENSAGEM : self::MENSAGEM_BOAS_VINDAS_FALHOU, $destino->email);
    }

    /**
     * As boas-vindas de teste pelo Carteiro. A confirmação já saiu e está contada: uma falha aqui vira aviso claro (false),
     * não um erro que pede repetir tudo.
     */
    private function enviarBoasVindas(string $para, ?string $nome): bool
    {
        try {
            $modelo = $this->modelos->montar(Automacao::Val, $nome);

            return $this->carteiro->enviar(new MensagemDeEmail(
                para: $para,
                assunto: self::PREFIXO . $modelo->assunto,
                corpoHtml: $modelo->corpoHtml,
                tipo: TipoDeEnvio::Transacional,
                origem: self::ORIGEM_BOAS_VINDAS,
                nome: $nome,
                selo: $modelo->selo,
                titulo: $modelo->titulo,
                layout: $modelo->layout,
            ))->foiEnfileirado();
        } catch (\Throwable) {
            return false;
        }
    }
}
