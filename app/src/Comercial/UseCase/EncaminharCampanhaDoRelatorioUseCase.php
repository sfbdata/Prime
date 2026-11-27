<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\TesteEnviado;
use App\Comercial\Enum\CentralDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Entity\Auth\User;

/**
 * "Encaminhar para um e-mail" do Relatório (desenho de marcas, comercial-marcas.md; pedido do Farlei M5.17, 23/11/2026):
 * relê a cópia congelada da campanha (a mesma que o visualizador abre, de qualquer estado) e a manda como teste, pelo
 * MESMO caminho do "Enviar teste": [TESTE] no assunto, teto de 10 por hora do Master, descadastro por endereço, linha
 * de teste no Relatório e `camp_teste` na trilha. Null se a campanha não existe.
 */
final class EncaminharCampanhaDoRelatorioUseCase
{
    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly EnviarTesteDaCampanhaUseCase $teste,
    ) {
    }

    /** @param non-empty-list<string> $para endereços já validados (DestinosDoTeste) */
    public function executar(int $id, User $master, array $para): ?TesteEnviado
    {
        $campanha = $this->campanhas->porId($id);
        if ($campanha === null) {
            return null;
        }
        try {
            $mensagem = $this->composicao->descongelar($campanha);
        } catch (CampanhaRecusada|\InvalidArgumentException) {
            throw new CampanhaRecusada('Esta campanha não pode mais ser montada para o encaminhamento.');
        }

        // C1: o rodapé com a frase da origem do público da campanha, como ela saiu. O teste encaminhado fica na mesma seção
        // da campanha (a antiga sem Central, na Comercial).
        return $this->teste->executar($mensagem, $master, $para, $campanha['publico'], CentralDaCampanha::tryFrom((string) $campanha['central']) ?? CentralDaCampanha::Comercial);
    }
}
