import React, { Component } from 'react'
import './App.css'

class App extends Component {
  state = {
    question: '',
    loading: false,
    error: '',
    messages: [
      {
        role: 'assistant',
        text: 'Ask a business question (revenue, distributor, or agent trends) to test the MVP.'
      }
    ],
    lastResponse: null
  }

  handleSubmit = (event) => {
    event.preventDefault()
    const question = this.state.question.trim()

    if (!question) {
      return
    }

    const nextMessages = this.state.messages.concat([{ role: 'user', text: question }])

    this.setState({ loading: true, error: '', messages: nextMessages })

    fetch('/.netlify/functions/ai-chat/query', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ question })
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Request failed with status ' + response.status)
        }
        return response.json()
      })
      .then((data) => {
        this.setState((prev) => ({
          question: '',
          loading: false,
          lastResponse: data,
          messages: prev.messages.concat([{ role: 'assistant', text: data.answer }])
        }))
      })
      .catch((error) => {
        this.setState({
          loading: false,
          error: error.message
        })
      })
  }

  render() {
    return (
      <div className='app-shell'>
        <header>
          <h1>Business Copilot MVP</h1>
          <p>Grounded Q&A over structured metrics + internal documents.</p>
        </header>

        <main>
          <section className='chat-panel'>
            <div className='messages'>
              {this.state.messages.map((message, index) => (
                <div key={index} className={'message ' + message.role}>
                  <strong>{message.role === 'assistant' ? 'AI' : 'You'}:</strong> {message.text}
                </div>
              ))}
            </div>

            <form onSubmit={this.handleSubmit} className='composer'>
              <input
                value={this.state.question}
                onChange={(event) => this.setState({ question: event.target.value })}
                placeholder='Which distributor earned the highest revenue last month?'
              />
              <button type='submit' disabled={this.state.loading}>
                {this.state.loading ? 'Thinking...' : 'Ask'}
              </button>
            </form>

            {this.state.error && <p className='error'>{this.state.error}</p>}
          </section>

          <section className='evidence-panel'>
            <h2>Evidence & checks</h2>
            {this.state.lastResponse ? (
              <div>
                <p>
                  <strong>Confidence:</strong> {this.state.lastResponse.confidence}
                </p>
                <p>
                  <strong>Trace ID:</strong> {this.state.lastResponse.trace_id}
                </p>
                <h3>Citations</h3>
                <ul>
                  {this.state.lastResponse.citations.map((citation, index) => (
                    <li key={index}>{citation.type === 'sql' ? citation.sql : citation.title + ' (' + citation.source + ')'}</li>
                  ))}
                </ul>
                <h3>Quality checks</h3>
                <ul>
                  {this.state.lastResponse.checks.map((check, index) => (
                    <li key={index}>{check}</li>
                  ))}
                </ul>
              </div>
            ) : (
              <p>No evidence yet. Ask a question to generate grounded output.</p>
            )}
          </section>
        </main>
      </div>
    )
  }
}

export default App
