<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\TesteEnviado;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase as Relatorio;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Enviar teste" (dc L.559; spec §8.4): o e-mail montado como a campanha vai a de 1 a 5 endereços (pedido do Farlei
 * M5.17, 23/11/2026: a C4, "só ao Master", caiu; sem endereço informado, vai só ao Master logado), pelo Carteiro,
 * `lista` `comercial_campanhas`, origem `teste_campanha` (fora do `campanha:` do Monitoramento), no layout do modo.
 * Isento do teto diário (`EnviarEmailHandler::ORIGENS_ISENTAS_DO_TETO_DIARIO`), sujeito ao limite por minuto, e com
 * teto próprio de 10 por hora por Master, em que cada endereço conta 1 (soma do `total` das linhas de teste dele, no
 * banco: sobrevive a deploy). O pedido que passa do que resta é recusado inteiro. Descadastro é por endereço: o Carteiro
 * bloqueia quem saiu da lista e o teste sai para os outros; se todos foram bloqueados, o pedido é recusado.
 * O modelo `vagas` passa pela mesma âncora da confirmação (§11): o teste sai como a campanha sairia.
 *
 * F3f (desenho 05 VS 21.10.26, L497-498, L668-669, L709, L717; decisão 2 da coordenação): o assunto chega com
 * "[TESTE]" e cada clique fica GRAVADO como uma linha de `comercial_campanha` com estado `teste` (fora de ATIVAS: o
 * expansor nunca a pega), público `teste`, `total` = quantos saíram, os destinatários em `comercial_campanha_destino`
 * cada um ligado ao seu envio (o endereço não vai para `publico_parametros`: ali não haveria anonimização, e o destino
 * já é anonimizado pela retenção). Assim o Relatório mostra a linha com o selo "Teste" e o KPI "Campanhas" a conta.
 * A trilha manual leva `camp_teste` com só `{total}`. O nome do Master vai no `{nome}` quando o endereço é o dele; os
 * outros seguem sem nome (o montador usa "colega", como numa campanha sem nome).
 * F3i (§12): a imagem do modo simples tem de existir e não ter vencido; o teste não renova a validade.
 */
final class EnviarTesteDaCampanhaUseCase
{
    public const ORIGEM = 'teste_campanha';
    public const TETO_POR_HORA = 10;
    public const TETO_ATINGIDO = 'Limite de 10 testes por hora atingido.';
    public const DESCADASTRADO = 'O endereço está descadastrado da lista de campanhas; o teste não foi enviado.';
    public const DESCADASTRADOS = 'Os endereços estão descadastrados da lista de campanhas; o teste não foi enviado.';
    public const NAO_REGISTRADO = 'O teste foi enviado, mas não entrou no Relatório. Não precisa enviar de novo.';
    public const PREFIXO = '[TESTE] ';
    public const PUBLICO = 'teste';

    public function __construct(
        private readonly ComposicaoDaCampanha $composicao,
        private readonly CarteiroInterface $carteiro,
        private readonly ClockInterface $relogio,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly DestinoDaCampanhaRepository $destinos,
        private readonly ImagensDaCampanha $imagens,
    ) {
    }

    /** @param list<string> $para endereços já validados (DestinosDoTeste); vazio = só o Master */
    public function executar(MensagemDaCampanha $mensagem, User $master, array $para = []): TesteEnviado
    {
        $para = $para === [] ? [mb_strtolower(trim((string) $master->getEmail()))] : $para;

        // Um teste por vez para cada Master: o teto, os envios e o registro (que é o contador) sob a mesma trava.
        return $this->campanhas->comTravaDoTesteDoMaster((int) $master->getId(), fn (): TesteEnviado => $this->enviar($mensagem, $master, $para));
    }

    /** @param non-empty-list<string> $para */
    private function enviar(MensagemDaCampanha $mensagem, User $master, array $para): TesteEnviado
    {
        $this->exigirTeto(\count($para), (int) $master->getId());
        ComposicaoDaCampanha::exigirAncoraDeVagas($mensagem);
        $this->imagens->exigirVigente($mensagem);
        $teste = self::comPrefixo($mensagem);
        $enviados = [];
        $bloqueados = [];
        try {
            foreach ($para as $endereco) {
                $nome = $endereco === mb_strtolower(trim((string) $master->getEmail())) ? $master->getFullName() : '';
                $email = $this->composicao->mensagemPara($teste, $endereco, $nome, self::ORIGEM);
                $envio = $this->carteiro->enviar($email);
                if ($envio->foiEnfileirado()) {
                    $enviados[] = ['email' => $email->para, 'nome' => (string) $nome, 'envio' => $envio->envioId];
                } else {
                    $bloqueados[] = $email->para;
                }
            }
        } catch (\Throwable $e) {
            // O que já saiu fica registrado; a falha segue para a tela.
            if ($enviados !== []) {
                $this->registrar($teste, $enviados, $master);
            }
            throw $e;
        }
        if ($enviados === []) {
            throw new CampanhaRecusada(\count($para) === 1 ? self::DESCADASTRADO : self::DESCADASTRADOS);
        }
        $this->registrar($teste, $enviados, $master);

        return new TesteEnviado(array_column($enviados, 'email'), $bloqueados);
    }

    /** Cada endereço conta 1: passou do que resta na hora, recusa o pedido inteiro. */
    private function exigirTeto(int $pedidos, int $masterId): void
    {
        $resta = self::TETO_POR_HORA - $this->campanhas->somarTestesDoMasterDesde($masterId, $this->relogio->now()->modify('-1 hour'));
        if ($resta <= 0) {
            throw new CampanhaRecusada(self::TETO_ATINGIDO);
        }
        if ($pedidos > $resta) {
            throw new CampanhaRecusada(sprintf('Limite de %d testes por hora: restam %d.', self::TETO_POR_HORA, $resta));
        }
    }

    /**
     * Os e-mails já saíram (o Carteiro fecha a própria transação): se a gravação falhar, a tela diz isso em vez de um
     * 500, para o Master não reenviar achando que nada aconteceu.
     *
     * @param list<array{email: string, nome: string, envio: int}> $enviados
     */
    private function registrar(MensagemDaCampanha $teste, array $enviados, User $master): void
    {
        try {
            $this->campanhas->emTransacao(fn () => $this->gravar($teste, $enviados, $master));
        } catch (\Throwable $e) {
            throw new CampanhaRecusada(self::NAO_REGISTRADO, 0, $e);
        }
    }

    /**
     * Depois dos envios enfileirados (o bloqueio do descadastro continua registrado pelo Carteiro, sem linha de teste):
     * a linha do teste, os destinatários e os envios ligados, e a trilha, numa transação.
     *
     * @param list<array{email: string, nome: string, envio: int}> $enviados
     */
    private function gravar(MensagemDaCampanha $teste, array $enviados, User $master): void
    {
        $agora = $this->relogio->now();
        $total = \count($enviados);
        $id = $this->campanhas->inserir([
            'chave' => 'teste_' . bin2hex(random_bytes(16)), 'assunto' => $teste->assunto, 'modelo_chave' => $teste->chave,
            'modelo_nome' => $teste->nome, 'modo' => $teste->modo, 'selo' => $teste->selo, 'titulo' => $teste->titulo,
            'conteudo' => $this->composicao->congelar($teste), 'publico' => self::PUBLICO,
            'parametros' => null,
            'frequencia' => CampanhaDeEmail::UNICA, 'agendada_para' => null, 'proxima' => $agora,
            'estado' => Relatorio::TESTE, 'total' => $total, 'criada_por' => $master->getId(), 'agora' => $agora,
        ]) ?? throw new CampanhaRecusada('Pedido inválido.');
        $this->destinos->materializar($id, 1, array_map(static fn (array $e): DestinatarioDaCampanha => new DestinatarioDaCampanha($e['email'], $e['nome']), $enviados), $agora);
        $this->campanhas->registrarExecucao($id, 1, $total, Relatorio::TESTE, null);
        foreach ($enviados as $e) {
            $this->destinos->vincularEnvioDoEndereco($id, mb_strtolower($e['email']), $e['envio']);
        }
        $this->campanhas->auditar($id, CampanhaDeEmailRepository::TESTADA, ['total' => $total], $master->getId(), $master->getEmail(), $agora);
    }

    private static function comPrefixo(MensagemDaCampanha $m): MensagemDaCampanha
    {
        $assunto = str_starts_with($m->assunto, self::PREFIXO) ? $m->assunto : mb_substr(self::PREFIXO . $m->assunto, 0, 255);

        return new MensagemDaCampanha($m->chave, $m->modo, $assunto, $m->selo, $m->titulo, $m->corpo, $m->itens, $m->cta, $m->link, $m->blocos, $m->html, $m->nome, $m->icone, $m->imagem);
    }
}
