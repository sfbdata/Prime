<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

final readonly class CriarPastaDTO
{
    /**
     * @param ?string $nup Número da pasta. `null` (ou em branco) = o sistema gera o próximo
     *                     livre do escritório — ver GerarNumeroDePasta. Continua aceitando um
     *                     número explícito porque a importação do acervo (CSV) e a descoberta
     *                     pelo Drive trazem o número da origem, que precisa ser preservado.
     * @param bool $vincularAoCadastroDoMesmoNome A Nova Pasta da tela (desenho 03, L276): se as pastas com
     *                     este nome de cliente apontam para um cadastro só, a nova entra ligada a ele. Os
     *                     caminhos automáticos (acervo, Drive, cobrança) não ligam nada por nome.
     * @param bool $criadorComoResponsavel A Nova demanda das Minhas Demandas (desenho 15, dc `salvarNovaFn`): quem
     *                     cria entra como responsável, senão a pasta não apareceria na própria lista. Os outros
     *                     caminhos (Expediente, acervo, Drive, cobrança) criam sem responsável, como sempre.
     */
    public function __construct(
        public ?string $nup = null,
        public ?string $nomeCliente = null,
        public ?string $nomeAcao = null,
        public bool $vincularAoCadastroDoMesmoNome = false,
        public bool $criadorComoResponsavel = false,
    ) {}
}
