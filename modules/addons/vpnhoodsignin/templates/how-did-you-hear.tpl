{* VpnHood! Sign-In — the one-question page, and the only client-area page this
   addon serves.

   It exists because signing up with Google skips the registration form, and the
   form is where this question would have been asked. It asks that one question
   and nothing else: the account already exists and works, so this is a courtesy
   the client can clear in a single click, not a second registration.

   Logout stays reachable, as it must on any page that holds someone. *}

<div class="card">
  <div class="card-body">

    {if $saved}
      <div class="alert alert-success" role="alert">
        Thank you &mdash; that is all we needed.
        <a href="clientarea.php">Continue to your account</a>.
      </div>
    {else}

      <h2 class="card-title">{$question|escape}</h2>

      {if $error}
        <div class="alert alert-danger" role="alert">{$error|escape}</div>
      {/if}

      <p class="text-muted">
        Your account is ready &mdash; signing in with Google skipped our sign-up form,
        so this is the one thing we did not get to ask. It takes a second, and we
        will not ask again.
      </p>

      <form method="post" action="index.php?m={$module|escape:'url'}">
        <div class="form-group">
          <label class="control-label" for="vpnhoodsignin-answer">{$question|escape}</label>
          <select name="answer" id="vpnhoodsignin-answer" class="form-control" required>
            <option value="">Please choose&hellip;</option>
            {foreach from=$options item=option}
              <option value="{$option|escape}">{$option|escape}</option>
            {/foreach}
          </select>
        </div>

        <button type="submit" class="btn btn-primary">Continue</button>
        <a href="logout.php" class="btn btn-default">Log out</a>
      </form>

    {/if}

  </div>
</div>
