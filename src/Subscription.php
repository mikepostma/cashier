<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at',
        'created_at', 'updated_at',
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user()
    {
        $model = getenv('STRIPE_MODEL') ?: config('services.stripe.model');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * Determine if the subscrition is active.
     *
     * @return bool
     */
    public function active()
    {
        return is_null($this->ends_at) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (! is_null($this->trial_ends_at)) {
            return Carbon::today()->lt($this->trial_ends_at);
        } else {
            return false;
        }
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (! is_null($endsAt = $this->ends_at)) {
            return Carbon::now()->lt(Carbon::instance($endsAt));
        } else {
            return false;
        }
    }

    /**
     * Increment the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementQuantity($count = 1)
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     *  Increment the quantity of the subscription. and invoice immediately.
     *
     * @param  int|null  $quantity
     * @return $this
     */
    public function incrementAndInvoice($quantity = null)
    {
        $this->incrementQuantity($quantity);

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function decrementQuantity($count = 1)
    {
        $this->updateQuantity(max(1, $this->quantity - $count));

        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param  int  $quantity
     * @param  \Stripe\Customer|null  $customer
     * @return $this
     */
    public function updateQuantity($quantity, $customer = null)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->quantity = $quantity;

        $subscription->save();

        $this->quantity = $quantity;

        $this->save();

        return $this;
    }

    /**
     * Swap the subscription to a new Stripe plan.
     *
     * @param  string  $plan
     * @return $this
     */
    public function swap($plan)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->plan = $plan;

        // If no specific trial end date has been set, the default behavior should be
        // to maintain the current trial state, whether that is "active" or to run
        // the swap out with the exact number of days left on this current plan.
        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        // Again, if no explicit quantity was set, the default behaviors should be to
        // maintain the current quantity onto the new plan. This is a sensible one
        // that should be the expected behavior for most developers with Stripe.
        if ($this->quantity) {
            $subscription->quantity = $this->quantity;
        }

        $subscription->save();

        $this->user->invoice();

        $this->fill([
            'stripe_plan' => $plan,
            'ends_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Cacnel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel(['at_period_end' => true]);

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
        } else {
            $this->ends_at = Carbon::createFromTimestamp(
                $subscription->current_period_end
            );
        }

        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel();

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->fill(['ends_at' => Carbon::now()])->save();
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     */
    public function resume()
    {
        if (! $this->onGracePeriod()) {
            throw new \LogicException('Unable to resume subscription that is not within grace period.');
        }

        $subscription = $this->asStripeSubscription();

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $subscription->plan = $this->stripe_plan;

        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        $subscription->save();

        // Finally, we will remove the ending timestamp from the user's record in the
        // local database to indicate that the subscription is active again and is
        // no longer "cancelled". Then we will save this record in the database.
        $this->fill(['ends_at' => null])->save();

        return $this;
    }

    /**
     * Get the subscription as a Stripe subscription object.
     *
     * @return \Stripe\Subscription
     */
    public function asStripeSubscription()
    {
        return $this->user->asStripeCustomer()->subscriptions->retrieve($this->stripe_id);
    }
}
