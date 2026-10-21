<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\CampanhaConfirmada;
use App\Comercial\DTO\PedidoDeCampanha;
use App\Comercial\DTO\PublicoResolvido;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Service\MontadorDeEmail;
use App\Comercial\Service\SementeDoExpansor;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Enviar campanha" (dc L.254 e L.560; spec §8.1, §8.4 e §8.5), depois da confirmação literal na tela. A campanha só
 * nasce aqui (não há rascunho) e nada sai daqui: o expansor entrega em lotes.
 *
 *   1. a chave da instalação desligada recusa ("O envio de campanhas está desligado nesta instalação.");
 *   2. a chave de idempotência já usada devolve a mesma campanha (clique duplo não cria duas);
 *   3. confere a mensagem, a data ("Escolha uma data futura.") e o público ("Nenhum destinatário nesta lista.");
 *   4. grava a cópia congelada, o público (no CSV e no Individual, só os e-mails que passaram) e a trilha manual
 *      (`camp_conf`, `{publico, frequencia, total}`), numa transação;
 *   5. acorda o expansor.
 *
 * Estado: recorrente na frequência que não é a única (sem data, começa agora); agendada com data; senão na fila.
 */
final class ConfirmarCampanhaUseCase
{
    public const DATA_PASSADA = 'Escolha uma data futura.';
    public const SEM_DESTINATARIOS = 'Nenhum destinatário nesta lista.';
    private const ENDERECO_DE_CONFERENCIA = 'conferencia@bluejus.com.br';

    public function __construct(
        private readonly ChaveDasCampanhas $chave,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly PublicosDaCampanha $publicos,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly SementeDoExpansor $expansor,
        private readonly MontadorDeEmail $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(PedidoDeCampanha $pedido, User $master): CampanhaConfirmada
    {
        if (!$this->chave->ligada()) {
            throw new CampanhaRecusada(ChaveDasCampanhas::DESLIGADA);
        }
        $existente = $this->campanhas->porChave($pedido->chave);
        if ($existente !== null) {
            return $this->confirmada($existente['id'], $existente['total'], $pedido);
        }
        $agora = $this->relogio->now();
        $this->exigirMensagemEData($pedido, $agora);
        $publico = $this->publicos->resolver($pedido->publico->publico, $pedido->publico->informados, $pedido->publico->filtro, $agora);
        if ($publico->total() === 0) {
            throw new CampanhaRecusada(self::SEM_DESTINATARIOS);
        }
        $id = $this->gravar($pedido, $publico, $master, $agora);
        $this->expansor->acordar($agora);

        return $this->confirmada($id, $publico->total(), $pedido);
    }

    private function exigirMensagemEData(PedidoDeCampanha $pedido, \DateTimeImmutable $agora): void
    {
        // Monta uma vez para um endereço de conferência: assunto ou conteúdo vazio é recusado com a mensagem da 2a.
        $this->composicao->mensagemPara($pedido->mensagem, self::ENDERECO_DE_CONFERENCIA, null, 'campanha:0');
        if ($pedido->agendarPara !== null && $pedido->agendarPara <= $agora) {
            throw new CampanhaRecusada(self::DATA_PASSADA);
        }
    }

    private function gravar(PedidoDeCampanha $pedido, PublicoResolvido $publico, User $master, \DateTimeImmutable $agora): int
    {
        $id = $this->campanhas->emTransacao(function () use ($pedido, $publico, $master, $agora): ?int {
            $id = $this->campanhas->inserir($this->linha($pedido, $publico, $master, $agora));
            if ($id !== null) {
                $changes = ['publico' => $pedido->publico->publico, 'frequencia' => $pedido->frequencia, 'total' => $publico->total()];
                $this->campanhas->auditar($id, CampanhaDeEmailRepository::CONFIRMADA, $changes, $master->getId(), $master->getEmail(), $agora);
            }

            return $id;
        });

        // A chave chegou ao mesmo tempo por outro pedido (clique duplo): vale a campanha que ele gravou.
        return $id ?? ($this->campanhas->porChave($pedido->chave)['id'] ?? throw new CampanhaRecusada('Pedido inválido.'));
    }

    /**
     * O que a campanha guarda para reaplicar a cada execução. Sem filtro, a lista do CSV ou do Individual já resolvida
     * (o formato de antes). Com filtro (§9), a lista que chegou ao filtro (já sem repetidos, descadastrados e barrados
     * pelo B1, para não guardar quem nunca vai receber) mais o filtro: a idade e o aniversário mudam de uma execução
     * para outra, então quem não bateu hoje pode bater no mês que vem.
     *
     * @return list<array{email: string, nome: string}>|array{informados: ?list<array{email: string, nome: string}>, filtro: array<string, string>}|null
     */
    private static function parametros(PedidoDeCampanha $pedido, PublicoResolvido $publico): ?array
    {
        $informado = \in_array($pedido->publico->publico, ['csv', 'indiv'], true);
        $filtro = $pedido->publico->filtro;
        if (!$filtro->ativo()) {
            return $informado ? array_map(static fn ($d): array => $d->paraArray(), $publico->destinatarios) : null;
        }

        return [
            'informados' => $informado ? array_map(static fn ($d): array => $d->paraArray(), $publico->antesDoFiltro()) : null,
            'filtro' => $filtro->paraArray(),
        ];
    }

    /** @return array{chave: string, assunto: string, modelo_chave: string, modelo_nome: string, modo: string, selo: string, titulo: string, conteudo: string, publico: string, parametros: list<array{email: string, nome: string}>|array{informados: ?list<array{email: string, nome: string}>, filtro: array<string, string>}|null, frequencia: string, agendada_para: ?\DateTimeImmutable, proxima: \DateTimeImmutable, estado: string, total: int, criada_por: ?int, agora: \DateTimeImmutable} */
    private function linha(PedidoDeCampanha $pedido, PublicoResolvido $publico, User $master, \DateTimeImmutable $agora): array
    {
        $m = $pedido->mensagem;
        $recorrente = $pedido->frequencia !== CampanhaDeEmail::UNICA;

        return [
            'chave' => $pedido->chave, 'assunto' => $m->assunto, 'modelo_chave' => $m->chave, 'modelo_nome' => $m->nome,
            'modo' => $m->modo, 'selo' => $m->selo, 'titulo' => $m->titulo, 'conteudo' => $this->composicao->congelar($m),
            'publico' => $pedido->publico->publico,
            'parametros' => self::parametros($pedido, $publico),
            'frequencia' => $pedido->frequencia,
            'agendada_para' => $pedido->agendarPara ?? ($recorrente ? $agora : null),
            'proxima' => $pedido->agendarPara ?? $agora,
            'estado' => $recorrente ? CampanhaDeEmail::RECORRENTE : ($pedido->agendarPara !== null ? CampanhaDeEmail::AGENDADA : CampanhaDeEmail::NA_FILA),
            'total' => $publico->total(), 'criada_por' => $master->getId(), 'agora' => $agora,
            'rastrear_leitura' => $pedido->rastrearLeitura,
        ];
    }

    /**
     * A frase do dc (L.560). Com o rastreio desligado (as duas chaves da 1a), termina em "registra as entregas.": "aberturas
     * e cliques" seria falso (decisão provisória 6).
     */
    private function confirmada(int $id, int $total, PedidoDeCampanha $pedido): CampanhaConfirmada
    {
        $amostra = $this->composicao->mensagemPara($pedido->mensagem, self::ENDERECO_DE_CONFERENCIA, null, 'campanha:' . $id, true);
        $registra = $this->montador->rastreia($amostra) ? 'registra entregas, aberturas e cliques.' : 'registra as entregas.';

        return new CampanhaConfirmada($id, $total, 'Campanha ' . ($pedido->agendarPara !== null ? 'agendada' : 'enfileirada') . ': ' . $total . ' e-mail(s). O servidor envia em lotes e ' . $registra);
    }
}
