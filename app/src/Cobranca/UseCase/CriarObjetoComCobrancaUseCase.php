<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Criação manual unificada do ajuste 2: criar um objeto JÁ inicia a sua cobrança.
 *
 * História: o gestor cadastra o objeto informando só o NOME de quem será cobrado. Numa passada, o
 * sistema cria o Objeto (via CriarObjetoUseCase — que já guarda o tenant da carteira), cadastra uma
 * Pessoa enxuta (só o nome; CPF/telefone/e-mail ficam para depois, na aba Pessoas), abre o Caso âncora
 * com essa pessoa como cobrada (herdando o snapshot de honorários da carteira e registrando o evento
 * "caso aberto") e registra o vínculo pessoa↔objeto (que a faz aparecer na aba Pessoas). Assim o objeto
 * nasce pronto para trabalhar, sem o passo separado de "abrir caso". O tipo do vínculo segue o preferido
 * da carteira (ou "Outro"). Carteira inexistente/de outro escritório é rejeitada dentro do
 * CriarObjetoUseCase (CarteiraNaoEncontradaException). NÃO é usado pela importação — esta orquestra suas
 * próprias etapas e reusa apenas o CriarObjetoUseCase cru.
 */
final class CriarObjetoComCobrancaUseCase
{
    public function __construct(
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly AbrirCasoUseCase $abrirCaso,
        private readonly VincularPessoaAObjetoUseCase $vincularPessoa,
    ) {
    }

    public function executar(CriarObjetoInput $input, Tenant $tenant, User $criadoPor): ObjetoCobranca
    {
        // Cria só o objeto (guarda multi-tenant da carteira herdada do CriarObjetoUseCase).
        $objeto = $this->criarObjeto->executar($input, $tenant, $criadoPor);

        // Pessoa enxuta: só o nome. Os demais dados entram depois, na aba Pessoas do objeto.
        $pessoaInput = new CriarPessoaInput();
        $pessoaInput->nome = trim((string) $input->nomeCobrado);
        $pessoa = $this->criarPessoa->executar($pessoaInput, $tenant, $criadoPor);

        // Caso âncora: herda o snapshot de honorários da carteira e registra o evento "caso aberto".
        $casoInput = new AbrirCasoInput();
        $casoInput->objetoId = $objeto->getId();
        $casoInput->pessoaCobradaId = $pessoa->getId();
        $this->abrirCaso->executar($casoInput, $tenant, $criadoPor);

        // Vínculo pessoa↔objeto: faz a pessoa cobrada aparecer na aba Pessoas.
        $vinculoInput = new VincularPessoaAObjetoInput();
        $vinculoInput->objetoId = $objeto->getId();
        $vinculoInput->pessoaId = $pessoa->getId();
        $vinculoInput->tipoVinculo = $objeto->getCarteira()?->getTipoVinculoPreferido() ?? TipoVinculo::Outro;
        $this->vincularPessoa->executar($vinculoInput, $tenant, $criadoPor);

        return $objeto;
    }
}
