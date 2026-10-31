<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escreve um cartão do SISTEMA no Registro da pasta, como o `autoReg` do desenho 1.2.3: "Andamento: Fase da
 * pasta alterada de X para Y", "Prazo: Audiência agendada: …". Autor = quem fez a mudança. Só persiste; quem
 * chama dá o flush junto da própria mudança.
 */
final class RegistroDoSistemaNaPasta
{
    /** Rótulos do desenho (`Component.SYS`); `prazo` não está no SYS do protótipo e ganhou o rótulo óbvio. */
    public const ROTULOS = [
        'fase'     => 'Andamento',
        'prazo'    => 'Prazo',
        'contrato' => 'Contrato',
        'situacao' => 'Situação da pasta',
        'cadastro' => 'Cadastro do cliente',
        // Apensado vinculado/desvinculado (P4f): `SYS.proc` do desenho, N:6168.
        'proc'     => 'Processo',
        'cadeado'  => 'Cadeado',
        // Desenho 02, `Component.SYS` (dc L6168): responsável, pró-bono e "Editar dados da pasta".
        'resp'     => 'Responsável',
        'probono'  => 'Pró-bono',
        'pasta'    => 'Dados da pasta',
        // "Mover para outra carteira" do menu ⋮ (desenho 02 VS 18.10.26, `menuPasta('mover')`).
        'carteira' => 'Carteira',
        // Rotina de segunda-feira da Controladoria (spec central-documentos-controladoria.md, "Rotina de segunda").
        'controladoria' => 'Controladoria',
    ];

    /**
     * Cartões do sistema que são dado FINANCEIRO (pró-bono e contrato, inclusive os do andamento): seguem a aba
     * Financeiro além da Dados. Somem da timeline e do Registro de quem tem o Financeiro oculto
     * (`VisibilidadeDasAbasDaPasta::abasDoEvento('registro_financeiro')`) e do contexto da I.A. sem o financeiro
     * (`FonteDeDadosDaPasta::anotacoesDaPasta`).
     */
    public const DO_FINANCEIRO = ['probono', 'contrato'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function registrar(Pasta $pasta, User $autor, string $tipo, string $texto): PastaMensagem
    {
        $rotulo = self::ROTULOS[$tipo] ?? 'Alteração';
        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setTenant($pasta->getTenant());
        $mensagem->setAutor($autor);
        $mensagem->setSistema($tipo);
        $mensagem->setConteudo('<p><strong>' . htmlspecialchars($rotulo, ENT_QUOTES) . ':</strong> '
            . htmlspecialchars($texto, ENT_QUOTES) . '</p>');
        $this->em->persist($mensagem);

        return $mensagem;
    }
}
