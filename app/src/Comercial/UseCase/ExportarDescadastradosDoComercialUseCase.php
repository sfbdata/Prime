<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\DescadastradoDoComercialOutput;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * O CSV da lista de Descadastrados (desenho 05 VS 21.10.26, L695): as mesmas linhas da tela (com a busca), todas as
 * páginas, colunas "E-mail;Data;Motivo;Origem", separador ";" e BOM para o Excel abrir os acentos.
 */
final class ExportarDescadastradosDoComercialUseCase
{
    public const ARQUIVO = 'descadastrados-bluejus.csv';

    public function __construct(
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    public function executar(?string $busca): string
    {
        $linhas = [self::linha(['E-mail', 'Data', 'Motivo', 'Origem'])];
        foreach ($this->todos($busca) as $item) {
            $linhas[] = self::linha([$item->email, $item->em->format('d/m/Y H:i'), $item->motivo, $item->origem]);
        }

        return "\u{FEFF}" . implode("\n", $linhas);
    }

    /** @return list<DescadastradoDoComercialOutput> de 100 em 100, o teto do contrato */
    private function todos(?string $busca): array
    {
        $itens = [];
        $pagina = 1;
        do {
            $lista = $this->descadastro->listar(null, $busca, $pagina, DescadastroDeEmailInterface::MAXIMO_POR_PAGINA, ResumirComercialUseCase::ASSUNTOS_DA_LISTA);
            array_push($itens, ...array_map(DescadastradoDoComercialOutput::deItem(...), $lista->itens));
            ++$pagina;
        } while (count($itens) < $lista->total && $lista->itens !== []);

        return $itens;
    }

    /**
     * Campo que começa com = + - @ ganha um apóstrofo: o motivo pode vir digitado por quem se descadastrou, e a
     * planilha não pode executá-lo como fórmula.
     *
     * @param list<string> $campos
     */
    private static function linha(array $campos): string
    {
        return implode(';', array_map(static function (string $c): string {
            $c = preg_match('/^[=+\-@]/', $c) === 1 ? "'" . $c : $c;

            return '"' . str_replace('"', '""', $c) . '"';
        }, $campos));
    }
}
