<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase as Relatorio;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Enviar teste" (dc L.559; spec §8.4, C4): o e-mail montado como a campanha vai SÓ ao Master logado, pelo Carteiro,
 * `lista` `comercial_campanhas`, origem `teste_campanha` (fora do `campanha:` do Monitoramento), no layout do modo.
 * Isento do teto diário (`EnviarEmailHandler::ORIGENS_ISENTAS_DO_TETO_DIARIO`), sujeito ao limite por minuto, e com
 * teto próprio de 10 por hora por Master (contado no registro dos envios, que sobrevive a deploy). Quem se
 * descadastrou da lista não recebe: o Carteiro registra o bloqueio e a tela diz por quê.
 *
 * F3f (desenho 05 VS 21.10.26, L497-498, L668-669, L709, L717; decisão 2 da coordenação): o assunto chega com
 * "[TESTE]" e o teste fica GRAVADO como uma linha de `comercial_campanha` com estado `teste` (fora de ATIVAS: o
 * expansor nunca a pega), público `teste`, o destinatário em `comercial_campanha_destino` ligado ao envio (o endereço não vai para
 * `publico_parametros`: ali não haveria anonimização, e o destino já é anonimizado pela retenção). Assim o
 * Relatório mostra a linha com o selo "Teste" e o KPI "Campanhas" a conta. O destino continua só o Master logado (o
 * "qualquer endereço" está com o dono). Descadastrado: o Carteiro registra o bloqueio e não há linha de teste.
 */
final class EnviarTesteDaCampanhaUseCase
{
    public const ORIGEM = 'teste_campanha';
    public const TETO_POR_HORA = 10;
    public const TETO_ATINGIDO = 'Limite de 10 testes por hora atingido.';
    public const DESCADASTRADO = 'Você está descadastrado da lista de campanhas; o teste não foi enviado.';
    public const NAO_REGISTRADO = 'O teste foi enviado, mas não entrou no Relatório. Não precisa enviar de novo.';
    public const PREFIXO = '[TESTE] ';
    public const PUBLICO = 'teste';

    public function __construct(
        private readonly ComposicaoDaCampanha $composicao,
        private readonly CarteiroInterface $carteiro,
        private readonly EnvioDeEmailRepository $envios,
        private readonly ClockInterface $relogio,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly DestinoDaCampanhaRepository $destinos,
    ) {
    }

    public function executar(MensagemDaCampanha $mensagem, User $master): string
    {
        $para = (string) $master->getEmail();
        $desde = $this->relogio->now()->modify('-1 hour');
        if ($this->envios->contarDaOrigemParaDesde(self::ORIGEM, $para, $desde) >= self::TETO_POR_HORA) {
            throw new CampanhaRecusada(self::TETO_ATINGIDO);
        }
        $teste = self::comPrefixo($mensagem);
        $email = $this->composicao->mensagemPara($teste, $para, $master->getFullName(), self::ORIGEM);
        $envio = $this->carteiro->enviar($email);
        if (!$envio->foiEnfileirado()) {
            throw new CampanhaRecusada(self::DESCADASTRADO);
        }
        $this->registrar($teste, $email, $master, $envio->envioId);

        return 'Teste enviado para ' . $email->para . '.';
    }

    /**
     * O e-mail já saiu (o Carteiro fecha a própria transação): se a gravação falhar, a tela diz isso em vez de um 500,
     * para o Master não reenviar achando que nada aconteceu.
     */
    private function registrar(MensagemDaCampanha $teste, MensagemDeEmail $email, User $master, int $envioId): void
    {
        try {
            $this->campanhas->emTransacao(fn () => $this->gravar($teste, $email, $master, $envioId));
        } catch (\Throwable $e) {
            throw new CampanhaRecusada(self::NAO_REGISTRADO, 0, $e);
        }
    }

    /**
     * Depois do envio enfileirado (o bloqueio do descadastro continua registrado pelo Carteiro, sem linha de teste): a
     * linha do teste, o destinatário e o envio ligados, numa transação.
     */
    private function gravar(MensagemDaCampanha $teste, MensagemDeEmail $email, User $master, int $envioId): void
    {
        $agora = $this->relogio->now();
        $id = $this->campanhas->inserir([
            'chave' => 'teste_' . bin2hex(random_bytes(16)), 'assunto' => $teste->assunto, 'modelo_chave' => $teste->chave,
            'modelo_nome' => $teste->nome, 'modo' => $teste->modo, 'selo' => $teste->selo, 'titulo' => $teste->titulo,
            'conteudo' => $this->composicao->congelar($teste), 'publico' => self::PUBLICO,
            'parametros' => null,
            'frequencia' => CampanhaDeEmail::UNICA, 'agendada_para' => null, 'proxima' => $agora,
            'estado' => Relatorio::TESTE, 'total' => 1, 'criada_por' => $master->getId(), 'agora' => $agora,
        ]) ?? throw new CampanhaRecusada('Pedido inválido.');
        $this->destinos->materializar($id, 1, [new DestinatarioDaCampanha($email->para, $master->getFullName())], $agora);
        $this->campanhas->registrarExecucao($id, 1, 1, Relatorio::TESTE, null);
        $this->destinos->vincularEnvioDaCampanha($id, $envioId);
    }

    private static function comPrefixo(MensagemDaCampanha $m): MensagemDaCampanha
    {
        $assunto = str_starts_with($m->assunto, self::PREFIXO) ? $m->assunto : mb_substr(self::PREFIXO . $m->assunto, 0, 255);

        return new MensagemDaCampanha($m->chave, $m->modo, $assunto, $m->selo, $m->titulo, $m->corpo, $m->itens, $m->cta, $m->link, $m->blocos, $m->html, $m->nome, $m->icone);
    }
}
