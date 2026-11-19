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
     * @param bool $criadorComoResponsavel Toda pasta nova nasce com quem a criou como responsável (spec
     *                     `acesso-restrito-e-compartilhar.md`, regra 6: Nova pasta, Nova demanda, importação em lote,
     *                     Chat I.A e judicializar). Só os caminhos sem uma pessoa abrindo a pasta passam `false`: o
     *                     acervo por CSV, a descoberta pelo Drive e a correção de pasta judicial duplicada.
     * @param bool $recusarNumeroEmUso Número digitado por uma pessoa (a proposta de pasta do Chat I.A): recusa se outra
     *                     pasta viva do escritório já o tem, sob a trava da numeração. O acervo e o Drive não ligam,
     *                     porque trazem o número da origem (10A/10B e repetidos do legado) e ele precisa ser preservado.
     */
    public function __construct(
        public ?string $nup = null,
        public ?string $nomeCliente = null,
        public ?string $nomeAcao = null,
        public bool $vincularAoCadastroDoMesmoNome = false,
        public bool $criadorComoResponsavel = true,
        public bool $recusarNumeroEmUso = false,
    ) {}
}
