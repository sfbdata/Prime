<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;

/**
 * O que a aba Push Processual da pasta precisa mostrar, já resolvido — a tela não decide nada.
 *
 * Os dois "vazios" desta aba têm causas diferentes e merecem textos diferentes: pasta SEM processo
 * vinculado nunca receberá publicação (é o caso de 991 das 1.079 pastas em produção), enquanto
 * pasta COM processo e sem publicação depende de o processo estar sob uma OAB monitorada. Dizer
 * "nenhuma publicação" nos dois casos esconderia do usuário o que ele precisa fazer.
 */
final readonly class PastaPushOutput
{
    /**
     * @param PublicacaoDjenListaItem[] $itens
     * @param string[]                  $numerosDigitos CNJ dos processos da pasta, só dígitos
     * @param array<int, NotaTecnicaOutput[]> $notasPorPublicacao id da publicação => notas dela
     */
    private function __construct(
        public array $itens,
        public bool $temProcesso,
        public int $total,
        public int $naoLidas,
        public bool $limiteAtingido,
        public ?string $numeroUnico,
        private array $numerosDigitos = [],
        private array $notasPorPublicacao = [],
    ) {}

    /**
     * @param PublicacaoDjenListaItem[] $itens publicações já casadas com os processos da pasta
     * @param string[]                  $numeros números CNJ dos processos vinculados à pasta
     * @param int                       $limite teto aplicado na consulta
     * @param NotaTecnica[]             $notas  notas penduradas nas publicações da lista, já
     *                                          recortadas por escritório e processo da pasta
     *                                          (NotaTecnicaRepository::listarPorPublicacoesDosProcessos),
     *                                          mais recente primeiro
     */
    public static function montar(array $itens, array $numeros, int $limite, array $notas = []): self
    {
        $numeros = array_values(array_filter(
            array_map(static fn (?string $n): string => trim((string) $n), $numeros),
            static fn (string $n): bool => $n !== '',
        ));

        $naoLidas = count(array_filter($itens, static fn (PublicacaoDjenListaItem $i): bool => !$i->lida));

        return new self(
            itens: array_values($itens),
            temProcesso: $numeros !== [],
            total: count($itens),
            naoLidas: $naoLidas,
            limiteAtingido: count($itens) >= $limite,
            // O módulo filtra por UM texto de busca. Com dois processos não há número único que
            // represente a pasta, e mandar um deles seria oferecer um recorte errado com cara de certo.
            numeroUnico: count($numeros) === 1 ? $numeros[0] : null,
            numerosDigitos: array_values(array_filter(array_map(self::soDigitos(...), $numeros), static fn (string $n): bool => $n !== '')),
            notasPorPublicacao: self::agruparNotas($notas, $itens),
        );
    }

    /**
     * FPN-020 (desenho 1.2.3, dc L.2534-2551, `m.temNotas`): as notas técnicas penduradas NESTA
     * movimentação, que a aba mostra sob o cartão sem abrir o teor. Vazio = o cartão não tem
     * bloco de notas na lista (e o teor traz o seu, com o compositor, como antes).
     *
     * @return NotaTecnicaOutput[]
     */
    public function notasDe(PublicacaoDjenListaItem $item): array
    {
        return $this->notasPorPublicacao[$item->id] ?? [];
    }

    /**
     * Agrupa por publicação, na ordem em que vieram (cronológica, a mais antiga primeiro: C6-FPN-01). Nota de publicação que
     * não está na lista fica de fora: só se pendura nota em cartão que existe.
     *
     * @param NotaTecnica[]             $notas
     * @param PublicacaoDjenListaItem[] $itens
     *
     * @return array<int, NotaTecnicaOutput[]>
     */
    private static function agruparNotas(array $notas, array $itens): array
    {
        $naLista = [];
        foreach ($itens as $item) {
            $naLista[$item->id] = true;
        }

        $porPublicacao = [];
        foreach ($notas as $nota) {
            $publicacaoId = $nota->getPublicacaoDjen()?->getId();
            if ($publicacaoId === null || !isset($naLista[$publicacaoId])) {
                continue;
            }
            $porPublicacao[$publicacaoId][] = NotaTecnicaOutput::fromEntity($nota);
        }

        return $porPublicacao;
    }

    /**
     * Filtro "Geram prazo" da aba (desenho 1.2.3, dc L.5414: `/Intima|Decis/.test(m.tipo)`): a regra
     * é a do desenho, sobre o TIPO da comunicação — não calcula prazo nenhum nem promete que haja
     * um (o motor de prazos é o D-PRAZOS, fora). Intimação e Decisão contam; Edital, Lista de
     * distribuição, Citação, Despacho e o tipo ausente não.
     *
     * Única diferença do literal: a comparação ignora caixa, como a cor da pílula do tipo já faz
     * ("INTIMAÇÃO" é a mesma comunicação). O trecho comparado não tem acento, então o "ç"/"ã" do
     * resto da palavra não interfere.
     */
    public static function tipoGeraPrazo(?string $tipoComunicacao): bool
    {
        return $tipoComunicacao !== null && preg_match('/intima|decis/iu', $tipoComunicacao) === 1;
    }

    public function geraPrazo(PublicacaoDjenListaItem $item): bool
    {
        return self::tipoGeraPrazo($item->tipoComunicacao);
    }

    /**
     * A movimentação recebe nota técnica? A nota é do PROCESSO da pasta a que a publicação
     * pertence (`pasta_push_teor` → `notaProcessoId`); sem ele, o teor não traz o bloco de notas e
     * o "Adicionar nota técnica" do menu (FPN-014c) não teria o que acionar. Mesma comparação do
     * controller do teor: só dígitos dos dois lados. O teor ainda acha o processo pela FK da
     * publicação; aqui só pelo número, que é por onde a lista inteira foi casada — na dúvida o
     * item some, nunca promete o que o teor não entrega.
     */
    public function aceitaNotaTecnica(PublicacaoDjenListaItem $item): bool
    {
        $numero = self::soDigitos($item->numeroProcessoExibicao);

        return $numero !== '' && in_array($numero, $this->numerosDigitos, true);
    }

    /**
     * FPN-020b: o processo DESTA pasta a que a publicação pertence — é nele que nasce a nota
     * criada no bloco pendurado sob o cartão. Mesmo critério do `aceitaNotaTecnica` e do
     * `pasta_push_teor` (só dígitos dos dois lados). Não vale "o processo da primeira nota": a
     * publicação pode ter notas antigas de OUTRO processo da pasta, e a nota nova iria para ele.
     * `null` quando nenhum processo da pasta casa com o número (aí não há onde criar a nota).
     */
    public function processoIdDaPublicacao(PublicacaoDjenListaItem $item, Pasta $pasta): ?int
    {
        $numero = self::soDigitos($item->numeroProcessoExibicao);
        if ($numero === '') {
            return null;
        }

        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $processo = $vinculo->getProcesso();
            if (self::soDigitos($processo->getNumeroProcesso()) === $numero) {
                return $processo->getId();
            }
        }

        return null;
    }

    /**
     * P4f: a chave do processo a que a movimentação pertence, para o filtro por processo no navegador
     * (`data-push-proc`). Só dígitos, o mesmo casamento de toda a aba.
     */
    public function chaveDoProcesso(PublicacaoDjenListaItem $item): string
    {
        return self::soDigitos($item->numeroProcessoExibicao);
    }

    /**
     * P4f (desenho `procFiltros`, N:5683): os chips "Todos os processos · N", "Principal · N" e um por
     * apensado "{Tipo} · N", com a contagem das movimentações da lista. Só existem com mais de duas
     * opções (principal e ao menos um apensado); senão, lista vazia e a tela não mostra chip nenhum.
     * Todos os apensados contam como ativos (o monitoramento por processo é de outra frente).
     *
     * @return list<array{chave: string, rotulo: string, n: int, numero: ?string}>
     */
    public function filtrosPorProcesso(Pasta $pasta): array
    {
        $opcoes    = [['chave' => 'todos', 'rotulo' => 'Todos os processos', 'n' => $this->total, 'numero' => null]];
        $principal = $pasta->getVinculoPrincipal();
        if ($principal !== null) {
            $opcoes[] = $this->opcaoDoVinculo($principal, 'Principal');
        }
        foreach ($pasta->getApensados() as $apensado) {
            $opcoes[] = $this->opcaoDoVinculo($apensado, $apensado->getTipoApensoRotulo());
        }

        return count($opcoes) > 2 ? $opcoes : [];
    }

    /**
     * P4f (desenho `pushDaPasta`, N:5483): a movimentação de um APENSADO ganha na fonte o sufixo
     * " · Apensado · {Tipo} {número}". Devolve o tipo e o número cru do apensado (a máscara é do Twig) ou
     * null quando a movimentação é do principal (ou de nenhum processo da pasta).
     *
     * @return array{tipo: string, numero: string}|null
     */
    public function apensadoDaPublicacao(PublicacaoDjenListaItem $item, Pasta $pasta): ?array
    {
        $chave = $this->chaveDoProcesso($item);
        if ($chave === '') {
            return null;
        }

        foreach ($pasta->getApensados() as $apensado) {
            $numero = (string) $apensado->getProcesso()->getNumeroProcesso();
            if (self::soDigitos($numero) === $chave) {
                return ['tipo' => $apensado->getTipoApensoRotulo(), 'numero' => $numero];
            }
        }

        return null;
    }

    /** @return array{chave: string, rotulo: string, n: int, numero: ?string} */
    private function opcaoDoVinculo(PastaProcesso $vinculo, string $rotulo): array
    {
        $numero = (string) $vinculo->getProcesso()->getNumeroProcesso();
        $chave  = self::soDigitos($numero);
        $n      = count(array_filter($this->itens, fn (PublicacaoDjenListaItem $i): bool => $this->chaveDoProcesso($i) === $chave));

        return ['chave' => $chave, 'rotulo' => $rotulo, 'n' => $n, 'numero' => $numero];
    }

    private static function soDigitos(?string $numero): string
    {
        return preg_replace('/\D+/', '', (string) $numero) ?? '';
    }
}
