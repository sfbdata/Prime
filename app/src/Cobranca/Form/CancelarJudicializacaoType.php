<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CancelarJudicializacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cancelar a judicialização de um caso (desvincula a pasta, volta o status para `Ativo`). `casoId`
 * vem da rota. Motivo obrigatório. Modal reutilizável no detalhe do caso.
 */
final class CancelarJudicializacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motivo', TextareaType::class, [
            'label' => 'Motivo do cancelamento',
            'attr' => ['class' => 'form-control', 'rows' => 2, 'placeholder' => 'Ex.: pasta excluída por engano, caso não deveria ter sido judicializado'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CancelarJudicializacaoInput::class,
        ]);
    }
}
