<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;
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
     * Agrupa por publicação, na ordem em que vieram (mais recente primeiro). Nota de publicação que
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

    private static function soDigitos(?string $numero): string
    {
        return preg_replace('/\D+/', '', (string) $numero) ?? '';
    }
}
